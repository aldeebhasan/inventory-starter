<?php

use Aldeebhasan\Inventorix\Enums\MovementType;
use Aldeebhasan\Inventorix\Models\Movement;
use Aldeebhasan\Inventorix\Models\Stock;
use App\Enums\ProductType;
use App\Filament\Exports\DeadStockReportExporter;
use App\Filament\Pages\DeadStockReport;
use App\Models\Brand;
use App\Models\Location;
use App\Models\Product;
use App\Models\User;
use Filament\Actions\ExportAction;
use Filament\Actions\Exports\Models\Export;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->location = createLocation();
    $this->product = Product::factory()->create(['type' => ProductType::Inventory]);
});

it('can render the dead stock report page', function () {
    Livewire::test(DeadStockReport::class)->assertSuccessful();
});

it('shows products with stock but no recent deductions', function () {
    $this->product->addStock(10, $this->location);

    // No deductions — should appear as dead stock
    Livewire::test(DeadStockReport::class)->assertSuccessful();
});

it('does not show products with recent deductions', function () {
    $this->product->addStock(20, $this->location);
    $this->product->deductStock(5, $this->location);

    // Recent deduction — should NOT appear
    Livewire::test(DeadStockReport::class)->assertSuccessful();
});

it('does not show zero-quantity stocks', function () {
    $this->product->addStock(5, $this->location);
    $this->product->deductStock(5, $this->location);

    Livewire::test(DeadStockReport::class)->assertSuccessful();
});

it('can filter by location', function () {
    $otherLocation = createLocation(['name' => 'Other Warehouse']);

    $this->product->addStock(10, $this->location);

    $product2 = Product::factory()->create(['type' => ProductType::Inventory]);
    $product2->addStock(5, $otherLocation);

    Livewire::test(DeadStockReport::class)
        ->filterTable('location_id', $this->location->id)
        ->assertSuccessful();
});

it('can filter by minimum inactive days', function () {
    $this->product->addStock(10, $this->location);

    Livewire::test(DeadStockReport::class)
        ->filterTable('min_days_inactive', '30')
        ->assertSuccessful();
});

it('does not run per-row queries as rows grow', function () {
    $seed = function (int $count): void {
        Product::factory()->count($count)->create(['brand_id' => Brand::factory()])
            ->each(fn (Product $product) => $product->addStock(10, $this->location));
    };

    $seed(2);
    $small = countQueries(fn () => Livewire::test(DeadStockReport::class)->assertCountTableRecords(2));

    $seed(6);
    $large = countQueries(fn () => Livewire::test(DeadStockReport::class)->assertCountTableRecords(8));

    expect($large)->toBe($small);
});

/** Stock with a deduction backdated by $daysAgo (null = never deducted). */
function deadStockWithLastDeduct(Location $location, ?int $daysAgo): Stock
{
    $product = Product::factory()->create(['cost' => 2.5, 'brand_id' => Brand::factory()]);
    $product->addStock(10, $location);

    if ($daysAgo !== null) {
        $product->deductStock(1, $location);
        Movement::query()
            ->where('stockable_id', $product->id)
            ->where('type', MovementType::Deduct)
            ->update(['created_at' => now()->subDays($daysAgo)]);
    }

    return Stock::query()->where('stockable_id', $product->id)->firstOrFail();
}

it('sorts by days since last movement with never-deducted stock as most inactive', function () {
    // Created in neither sort order, so an unsorted (id-ordered) result fails both assertions.
    $newer = deadStockWithLastDeduct($this->location, 100);
    $never = deadStockWithLastDeduct($this->location, null);
    $older = deadStockWithLastDeduct($this->location, 200);

    Livewire::test(DeadStockReport::class)
        ->sortTable('days_inactive', 'desc')
        ->assertCanSeeTableRecords([$never, $older, $newer], inOrder: true)
        ->sortTable('days_inactive', 'asc')
        ->assertCanSeeTableRecords([$newer, $older, $never], inOrder: true);
});

it('shows last movement date and days inactive from the report query', function () {
    deadStockWithLastDeduct($this->location, 120);

    Livewire::test(DeadStockReport::class)
        ->assertSee(now()->subDays(120)->format('M d, Y'))
        ->assertSee('120');
});

it('dispatches the dead stock export', function () {
    $exports = ExportAction::fake();

    Livewire::test(DeadStockReport::class)
        ->callAction(TestAction::make('export')->table())
        ->assertHasNoFormErrors();

    $exports->assertDispatched(DeadStockReportExporter::class);
});

it('exports computed row values', function () {
    $stock = deadStockWithLastDeduct($this->location, 120);
    $record = DeadStockReport::getReportQuery(90)->whereKey($stock->id)->firstOrFail();

    $row = DeadStockReportExporter::test()->export($record);

    expect($row)->toBe([
        $record->stockable->name,
        $record->stockable->brand->name,
        $this->location->name,
        '9.0000',
        now()->subDays(120)->format('M d, Y'),
        '120',
        '22.5',
    ]);
});

it('runs a real dead stock export end to end', function () {
    Storage::fake(config('filament.default_filesystem_disk', 'local'));
    deadStockWithLastDeduct($this->location, null);
    deadStockWithLastDeduct($this->location, 120);

    Livewire::test(DeadStockReport::class)
        ->callAction(TestAction::make('export')->table())
        ->assertHasNoFormErrors();

    $export = Export::query()->latest('id')->firstOrFail();
    $csv = $export->getFileDisk()->get($export->getFileDirectory().'/0000000000000001.csv');

    expect($export->successful_rows)->toBe(2)
        ->and($csv)->toContain('Never')
        ->and($csv)->toContain(now()->subDays(120)->format('M d, Y'));
});
