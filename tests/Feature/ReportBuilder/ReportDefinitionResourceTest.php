<?php

use Aldeebhasan\Panorama\Queue\ReportQueue;
use App\Filament\Resources\ReportDefinitions\Pages\BuildReport;
use App\Filament\Resources\ReportDefinitions\Pages\CreateReport;
use App\Filament\Resources\ReportDefinitions\Pages\ListReportDefinitions;
use App\Filament\Resources\ReportDefinitions\ReportDefinitionResource;
use App\Filament\Resources\ReportDefinitions\Widgets\ReportRunsWidget;
use App\Jobs\RunDefinedReportJob;
use App\Models\Product;
use App\Models\ReportDefinition;
use App\Models\SaleOrderItem;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertSoftDeleted;

describe('model', function () {
    it('casts definition and is_shared', function () {
        $report = ReportDefinition::factory()->shared()->create();

        expect($report->fresh()->definition)->toBeArray()->toHaveKey('base', 'products')
            ->and($report->is_shared)->toBeTrue();
    });

    it('belongs to its owner', function () {
        $report = ReportDefinition::factory()->create();

        expect($report->user)->toBeInstanceOf(User::class);
    });

    it('scopes to own and shared reports', function () {
        $me = User::factory()->create();
        $mine = ReportDefinition::factory()->for($me)->create();
        $shared = ReportDefinition::factory()->shared()->create();
        $othersPrivate = ReportDefinition::factory()->create();

        $visible = ReportDefinition::visibleTo($me)->pluck('id');

        expect($visible)->toContain($mine->id, $shared->id)->not->toContain($othersPrivate->id)
            ->and($othersPrivate->isVisibleTo($me))->toBeFalse()
            ->and($shared->isVisibleTo($me))->toBeTrue()
            ->and($shared->isOwnedBy($me))->toBeFalse();
    });

    it('is keyed for panorama by id and last edit', function () {
        $report = ReportDefinition::factory()->create();
        $before = $report->reportKey();

        $this->travel(1)->minute();
        $report->update(['name' => 'Renamed']);

        expect($before)->toStartWith("defined-{$report->id}-")
            ->and($report->reportKey())->not->toBe($before)->toStartWith("defined-{$report->id}-");
    });
});

describe('resource', function () {
    beforeEach(function () {
        $this->me = User::factory()->create();
        $this->actingAs($this->me);
    });

    it('lists my reports and shared ones, never others\' private ones', function () {
        $mine = ReportDefinition::factory()->for($this->me)->create();
        $shared = ReportDefinition::factory()->shared()->create();
        $private = ReportDefinition::factory()->create();

        Livewire::test(ListReportDefinitions::class)
            ->assertCanSeeTableRecords([$mine, $shared])
            ->assertCanNotSeeTableRecords([$private]);
    });

    it('searches and filters by owner', function () {
        $mine = ReportDefinition::factory()->for($this->me)->create(['name' => 'Monthly revenue']);
        $shared = ReportDefinition::factory()->shared()->create(['name' => 'Stock levels']);

        Livewire::test(ListReportDefinitions::class)
            ->searchTable('Monthly')
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$shared]);

        Livewire::test(ListReportDefinitions::class)
            ->filterTable('mine', false)
            ->assertCanSeeTableRecords([$shared])
            ->assertCanNotSeeTableRecords([$mine]);
    });

    it('soft-deletes and restores my report', function () {
        $report = ReportDefinition::factory()->for($this->me)->create();

        Livewire::test(ListReportDefinitions::class)->callAction(TestAction::make('delete')->table($report));
        assertSoftDeleted(ReportDefinition::class, ['id' => $report->id]);

        Livewire::test(ListReportDefinitions::class)->callAction(TestAction::make('restore')->table($report));
        assertDatabaseHas(ReportDefinition::class, ['id' => $report->id, 'deleted_at' => null]);
    });

    it('opens a report shared with me without edit or delete', function () {
        $shared = ReportDefinition::factory()->shared()->create();

        Livewire::test(ListReportDefinitions::class)
            ->assertActionHidden(TestAction::make('delete')->table($shared));

        Livewire::test(BuildReport::class, ['record' => $shared->id])
            ->assertSuccessful()
            ->assertSee('This report is shared with you')
            ->assertActionHidden('save')
            ->assertActionHidden('delete');
    });

    it('hides others\' private reports', function () {
        $private = ReportDefinition::factory()->create();

        $this->get(ReportDefinitionResource::getUrl('view', ['record' => $private]))->assertNotFound();
    });
});

describe('builder', function () {
    beforeEach(function () {
        $this->me = User::factory()->create();
        $this->actingAs($this->me);
        $this->widget = Product::factory()->create(['name' => 'Widget', 'price' => 12.5]);
        $this->gadget = Product::factory()->create(['name' => 'Gadget', 'price' => 3]);
        SaleOrderItem::factory()->create(['product_id' => $this->widget->id, 'quantity' => 4]);
        SaleOrderItem::factory()->create(['product_id' => $this->gadget->id, 'quantity' => 1]);
    });

    it('renders the full builder page', function () {
        // Full page renders need the Vite manifest, which CI doesn't build.
        $this->withoutVite();
        $report = ReportDefinition::factory()->for($this->me)->create(['definition' => [
            'base' => 'products', 'group_by' => ['products.name'], 'visualization' => 'bar',
            'aggregates' => [['fn' => 'sum', 'field' => 'products.price', 'as' => 'sum_price']],
            'columns' => [['field' => 'products.name', 'label' => 'Product'], ['field' => 'sum_price', 'label' => 'Price', 'format' => 'money', 'total' => true]],
        ]]);

        $this->get(ReportDefinitionResource::getUrl('create'))->assertOk()->assertSee('What do you want to report on?');
        $this->get(ReportDefinitionResource::getUrl('view', ['record' => $report]))->assertOk()->assertSee('reportChart')->assertSee('Widget')
            // The loading overlay starts hidden; Livewire shows it only while a request runs.
            ->assertSee('wire:loading.delay.flex class="absolute inset-0 z-10 hidden', false);
    });

    it('starts by choosing a dataset', function () {
        Livewire::test(CreateReport::class)
            ->assertSee('What do you want to report on?')
            ->assertSee('Sale Order Items')
            ->call('setBase', 'sale_order_items')
            ->assertSet('definition.base', 'sale_order_items')
            ->assertSet('definition.date_range.preset', 'last_30_days')
            ->assertSet('definition.parameters', ['from', 'to'])
            ->assertSee('Count of rows')
            ->assertSee('Products · via sale_order_items.product_id');
    });

    it('builds a grouped report with an auto-join and a bar chart, then saves it', function () {
        $page = Livewire::test(CreateReport::class)
            ->call('setBase', 'sale_order_items')
            ->call('addDimension', 'name', 'sale_order_items.product_id>products.id')
            ->call('addMetric', 'sum', 'sale_order_items.quantity')
            ->call('sortBy', 'products.name')
            ->call('setVisualization', 'bar')
            ->assertSee('Gadget')
            ->assertSee('Widget')
            ->assertSee('Total quantity')
            ->assertSet('chart.type', 'bar')
            ->assertSet('chart.labels', ['Gadget', 'Widget'])
            ->assertSet('dirty', true)
            ->set('name', 'Quantity by product')
            ->callAction('save');

        $report = ReportDefinition::sole();
        $page->assertRedirect(ReportDefinitionResource::getUrl('view', ['record' => $report]));

        expect($report->user_id)->toBe($this->me->id)
            ->and($report->name)->toBe('Quantity by product')
            ->and($report->definition['joins'][0]['alias'])->toBe('products')
            ->and($report->definition['group_by'])->toBe(['products.name'])
            ->and($report->definition['visualization'])->toBe('bar');
    });

    it('opens a saved report and saves changes', function () {
        $report = ReportDefinition::factory()->for($this->me)->create();

        Livewire::test(BuildReport::class, ['record' => $report->id])
            ->assertSet('name', $report->name)
            ->assertSet('dirty', false)
            ->call('addDimension', 'products.price')
            ->callAction('settings', ['is_shared' => true, 'description' => 'For the team'])
            ->callAction('save')
            ->assertSet('dirty', false);

        expect($report->fresh())
            ->is_shared->toBeTrue()
            ->description->toBe('For the team')
            ->and(array_column($report->fresh()->definition['columns'], 'field'))->toBe(['products.name', 'products.price']);
    });

    it('renames a saved report', function () {
        $report = ReportDefinition::factory()->for($this->me)->create();

        Livewire::test(BuildReport::class, ['record' => $report->id])
            ->assertSet('dirty', false)
            ->set('name', 'Renamed report')
            ->assertSet('dirty', true)
            ->assertActionEnabled('save')
            ->callAction('save')
            ->assertSet('dirty', false);

        expect($report->fresh()->name)->toBe('Renamed report');
    });

    it('sends the name to the server when the field loses focus', function () {
        // Livewire 4: a bare `.blur` only syncs in the browser, so Save would never learn of the change.
        Livewire::test(BuildReport::class, ['record' => ReportDefinition::factory()->for($this->me)->create()->id])
            ->assertSeeHtml('wire:model.live.blur="name"');
    });

    it('refuses to save an unnamed or broken report', function () {
        Livewire::test(CreateReport::class)
            ->call('setBase', 'products')
            ->set('name', ' ')
            ->callAction('save')
            ->assertNotified('The report cannot be saved');

        expect(ReportDefinition::count())->toBe(0);
    });

    it('filters, regroups dates and shows a KPI against the previous period', function () {
        Livewire::test(CreateReport::class)
            ->call('setBase', 'sale_order_items')
            ->call('addMetric', 'sum', 'sale_order_items.quantity')
            ->call('setVisualization', 'kpi')
            ->call('toggleCompare')
            ->assertSee('compared to')
            ->assertSee('5.00')
            ->call('addFilter', 'sale_order_items.quantity', '>', '2')
            ->assertSee('4.00')
            ->call('removeFilter', 'filters', 0)
            ->call('addDimension', 'sale_order_items.created_at__day')
            ->call('setBucket', 'sale_order_items.created_at__day', 'month')
            ->assertSet('definition.group_by', ['sale_order_items.created_at__month'])
            ->assertSet('definition.visualization', 'kpi');
    });

    it('says when the date range hides every row and offers all time', function () {
        SaleOrderItem::query()->update(['created_at' => now()->subDays(60)]);

        Livewire::test(CreateReport::class)
            ->call('setBase', 'sale_order_items')
            ->call('addDimension', 'sale_order_items.id')
            ->assertSee('No data between')
            ->assertSee('Show all time')
            ->call('setRange', 'all_time')
            ->assertDontSee('No data between')
            ->assertSee('Count of rows');
    });

    it('switches date presets and drops date parameters for all time', function () {
        Livewire::test(CreateReport::class)
            ->call('setBase', 'sale_order_items')
            ->call('setRange', 'all_time')
            ->assertSet('definition.parameters', [])
            ->call('setRange', 'custom', '2026-01-01', '2026-01-31')
            ->assertSet('definition.date_range.from', '2026-01-01')
            ->assertSet('definition.parameters', ['from', 'to']);
    });

    it('highlights fields already used as dimensions, dates grouped by bucket too', function () {
        Livewire::test(CreateReport::class)
            ->call('setBase', 'sale_orders')
            ->assertDontSeeHtml('aria-current="true"')
            ->call('addDimension', 'sale_orders.status')
            ->call('addDimension', 'sale_orders.created_at__day')
            ->call('setBucket', 'sale_orders.created_at__day', 'month')
            ->assertSeeHtml('wire:click="removeDimension(\'sale_orders.status\')"')
            ->assertSeeHtml('wire:click="removeDimension(\'sale_orders.created_at__month\')"')
            ->call('removeDimension', 'sale_orders.status')
            ->assertDontSeeHtml('wire:click="removeDimension(\'sale_orders.status\')"');
    });

    it('highlights fields used as metrics apart from dimensions', function () {
        Livewire::test(CreateReport::class)
            ->call('setBase', 'sale_order_items')
            ->call('addMetric', 'count')
            ->call('addMetric', 'sum', 'sale_order_items.quantity')
            ->call('addMetric', 'avg', 'sale_order_items.quantity')
            ->assertSeeHtml('wire:click="removeMetric(\'count_rows\')"')
            ->assertSeeHtml('wire:click="removeMetric(\'sum_sale_order_items_quantity\')"')
            ->assertSeeHtml('wire:click="removeMetric(\'avg_sale_order_items_quantity\')"')
            ->assertSee('Average quantity')
            ->assertSeeHtml('bg-success-50');
    });

    it('ignores a relation the report cannot follow', function () {
        Livewire::test(CreateReport::class)
            ->call('setBase', 'products')
            ->call('addDimension', 'name', 'suppliers.id>customers.id')
            ->assertSet('definition.joins', []);
    });

    it('explains a stale saved definition instead of running it', function () {
        $report = ReportDefinition::factory()->for($this->me)->create(['definition' => ['base' => 'products', 'columns' => [['field' => 'products.gone']]]]);

        Livewire::test(BuildReport::class, ['record' => $report->id])
            ->assertSee('This report needs editing')
            ->assertSee('Column &quot;products.gone&quot;: unknown field.', false);
    });

    it('streams downloads and queues exports of the saved report', function () {
        Queue::fake();
        $report = ReportDefinition::factory()->for($this->me)->create();

        Livewire::test(BuildReport::class, ['record' => $report->id])
            ->callAction(TestAction::make('downloadCsv'))
            ->assertFileDownloaded()
            ->callAction(TestAction::make('exportXlsx'));

        Queue::assertPushed(RunDefinedReportJob::class, fn (RunDefinedReportJob $job): bool => $job->format === 'xlsx' && $job->userId === $this->me->id);
    });

    it('asks to save before exporting unsaved changes', function () {
        Queue::fake();
        $report = ReportDefinition::factory()->for($this->me)->create();

        Livewire::test(BuildReport::class, ['record' => $report->id])
            ->call('addDimension', 'products.price')
            ->callAction(TestAction::make('exportHtml'))
            ->assertNotified('Save the report first');

        Queue::assertNothingPushed();
    });

    it('deletes my report', function () {
        $report = ReportDefinition::factory()->for($this->me)->create();

        Livewire::test(BuildReport::class, ['record' => $report->id])
            ->callAction('delete')
            ->assertRedirect(ReportDefinitionResource::getUrl());

        assertSoftDeleted(ReportDefinition::class, ['id' => $report->id]);
    });

    it('lists the report runs with a download only for my stored files', function () {
        $report = ReportDefinition::factory()->for($this->me)->create();
        Storage::fake(config('panorama.storage.disk'));
        (new RunDefinedReportJob($report->id, [], $this->me->id, 'csv'))->handle(app(ReportQueue::class));
        $run = $report->runs()->sole();

        Livewire::test(ReportRunsWidget::class, ['record' => $report])
            ->assertCanSeeTableRecords([$run])
            ->assertSee($this->me->name)
            ->assertActionVisible(TestAction::make('download')->table($run));

        $this->actingAs(User::factory()->create());

        Livewire::test(ReportRunsWidget::class, ['record' => $report])
            ->assertCanNotSeeTableRecords([$run]);
    });
});
