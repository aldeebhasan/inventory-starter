<?php

namespace App\Filament\Resources\ReportDefinitions\Pages;

use Aldeebhasan\Panorama\Exceptions\PanoramaException;
use Aldeebhasan\Panorama\Facades\Panorama;
use Aldeebhasan\Panorama\Report\ReportRun;
use App\Filament\Resources\ReportDefinitions\ReportDefinitionResource;
use App\Filament\Resources\ReportDefinitions\Widgets\ReportRunsWidget;
use App\Jobs\RunDefinedReportJob;
use App\Models\ReportDefinition;
use App\Reports\DateRange;
use App\Reports\DefinedReport;
use App\Reports\DefinitionEditor;
use App\Reports\ReportPreview;
use App\Reports\ReportSchema;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The visual report builder: pick fields on the left, see the chart and rows update on the right.
 * Opens saved reports too; anyone a report is shared with can explore it, only its owner can save it.
 */
class BuildReport extends Page
{
    protected static string $resource = ReportDefinitionResource::class;

    protected string $view = 'filament.resources.report-definitions.build-report';

    protected Width|string|null $maxContentWidth = Width::Full;

    /** Which datasets the start screen offers under which heading. */
    private const SECTIONS = [
        'Sales' => ['sale_orders', 'sale_order_items', 'customers', 'return_orders', 'return_order_items'],
        'Purchase' => ['purchase_orders', 'purchase_order_items', 'suppliers'],
        'Inventory' => ['stock_movements', 'stocks', 'stock_transactions', 'reservations', 'transfer_orders', 'transfer_order_items', 'stock_adjustments', 'stock_adjustment_items', 'locations'],
        'Catalog' => ['products', 'categories', 'product_categories', 'brands', 'units', 'addons'],
    ];

    #[Locked]
    public ?int $recordId = null;

    public string $name = '';

    public ?string $description = null;

    public bool $isShared = false;

    /** @var array<string, mixed>|null */
    public ?array $definition = null;

    public bool $dirty = false;

    private ?ReportDefinition $loaded = null;

    /** @var array<string, mixed>|null the chart the Alpine component draws */
    public ?array $chart = null;

    public function mount(int|string|null $record = null): void
    {
        if ($record === null) {
            $this->name = 'Untitled report';

            return;
        }

        // Others' private reports are not visible, so they 404 here.
        $report = ReportDefinition::query()->visibleTo(auth()->user())->findOrFail($record);

        $this->recordId = $report->id;
        $this->name = $report->name;
        $this->description = $report->description;
        $this->isShared = $report->is_shared;
        $this->definition = ReportSchema::normalize($report->definition);
    }

    public function getTitle(): string|Htmlable
    {
        return $this->recordId === null ? 'New report' : $this->name;
    }

    public function getBreadcrumbs(): array
    {
        return [ReportDefinitionResource::getUrl() => 'Reports', $this->recordId === null ? 'New' : 'Builder'];
    }

    /*
    |--------------------------------------------------------------------------
    | Edits, called from the canvas
    |--------------------------------------------------------------------------
    */

    public function updatedName(): void
    {
        $this->dirty = true;
    }

    public function setBase(string $dataset): void
    {
        if (! array_key_exists($dataset, app(ReportSchema::class)->datasetOptions())) {
            return;
        }

        $this->definition = $this->editor()->setDateRange(DefinitionEditor::blank($dataset), ['preset' => 'last_30_days']);
        $this->dirty = true;
    }

    public function resetBase(): void
    {
        $this->definition = null;
        $this->dirty = true;
    }

    public function addDimension(string $field, ?string $relation = null): void
    {
        $this->edit(fn (array $definition): array => $this->editor()->addDimension($definition, $field, $relation), $relation);
    }

    public function removeDimension(string $field): void
    {
        $this->edit(fn (array $definition): array => $this->editor()->removeDimension($definition, $field));
    }

    public function setBucket(string $field, ?string $bucket = null): void
    {
        $bucket = in_array($bucket, ['day', 'week', 'month', 'year'], true) ? $bucket : null;

        $this->edit(fn (array $definition): array => $this->editor()->setBucket($definition, $field, $bucket));
    }

    public function addMetric(string $fn, ?string $field = null, ?string $relation = null): void
    {
        if (! in_array($fn, ReportSchema::AGGREGATES, true)) {
            return;
        }

        $this->edit(fn (array $definition): array => $this->editor()->addMetric($definition, $fn, $field, $relation), $relation);
    }

    public function removeMetric(string $as): void
    {
        $this->edit(fn (array $definition): array => $this->editor()->removeMetric($definition, $as));
    }

    public function setMetricFunction(string $as, string $fn): void
    {
        $this->edit(fn (array $definition): array => $this->editor()->setMetricFunction($definition, $as, $fn));
    }

    public function updateColumn(string $field, string $label, string $format): void
    {
        $this->edit(fn (array $definition): array => $this->editor()->updateColumn($definition, $field, $label, $format));
    }

    public function addFilter(string $field, string $operator, mixed $value = null): void
    {
        if (! in_array($operator, ReportSchema::OPERATORS, true)) {
            return;
        }

        $this->edit(fn (array $definition): array => $this->editor()->addFilter($definition, $field, $operator, $value));
    }

    public function removeFilter(string $list, int $index): void
    {
        $this->edit(fn (array $definition): array => $this->editor()->removeFilter($definition, $list, $index));
    }

    public function sortBy(string $column): void
    {
        $this->edit(fn (array $definition): array => $this->editor()->sortBy($definition, $column));
    }

    public function setLimit(?int $limit = null): void
    {
        $this->edit(fn (array $definition): array => ['limit' => $limit !== null && $limit > 0 ? $limit : null] + $definition);
    }

    public function setVisualization(string $type): void
    {
        $this->edit(fn (array $definition): array => ['visualization' => in_array($type, ReportSchema::VISUALIZATIONS, true) ? $type : 'table'] + $definition);
    }

    public function setRange(string $preset, ?string $from = null, ?string $to = null): void
    {
        $this->edit(fn (array $definition): array => $this->editor()->setDateRange($definition, [
            'preset' => $preset,
            'from' => $from,
            'to' => $to,
            'compare' => $definition['date_range']['compare'],
        ]));
    }

    public function toggleCompare(): void
    {
        $this->edit(fn (array $definition): array => $this->editor()->setDateRange($definition, ['compare' => ! $definition['date_range']['compare']] + $definition['date_range']));
    }

    /*
    |--------------------------------------------------------------------------
    | Header actions
    |--------------------------------------------------------------------------
    */

    protected function getHeaderActions(): array
    {
        return [
            Action::make('settings')
                ->icon(Heroicon::OutlinedCog6Tooth)
                ->color('gray')
                ->visible(fn (): bool => $this->canSave())
                ->fillForm(fn (): array => ['description' => $this->description, 'is_shared' => $this->isShared])
                ->schema([
                    Toggle::make('is_shared')->label('Shared with all users')->helperText('Others can open and explore it; only you can save it.'),
                    Textarea::make('description'),
                ])
                ->action(function (array $data): void {
                    $this->description = $data['description'];
                    $this->isShared = (bool) $data['is_shared'];
                    $this->dirty = true;
                }),

            ActionGroup::make([
                Action::make('downloadCsv')->label('Download CSV')->action(fn (): ?StreamedResponse => $this->download('csv')),
                Action::make('downloadJson')->label('Download JSON')->action(fn (): ?StreamedResponse => $this->download('json')),
                Action::make('exportXlsx')->label('Export XLSX')->action(fn () => $this->export('xlsx')),
                Action::make('exportHtml')->label('Export HTML')->action(fn () => $this->export('html')),
            ])
                ->label('Export')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->button()
                ->color('gray')
                ->visible(fn (): bool => $this->recordId !== null),

            Action::make('save')
                ->label(fn (): string => $this->dirty ? 'Save' : 'Saved')
                ->icon(Heroicon::OutlinedCheck)
                ->disabled(fn (): bool => ! $this->dirty || $this->definition === null)
                ->visible(fn (): bool => $this->canSave())
                ->action(fn () => $this->save()),

            Action::make('delete')
                ->color('danger')
                ->icon(Heroicon::OutlinedTrash)
                ->iconButton()
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->recordId !== null && $this->canSave())
                ->action(function (): void {
                    $this->record()->delete();
                    $this->redirect(ReportDefinitionResource::getUrl());
                }),
        ];
    }

    public function save(): void
    {
        abort_unless($this->canSave(), 403);

        $name = trim($this->name);
        $errors = $this->definition === null ? ['Choose a dataset to start from.'] : app(ReportSchema::class)->errors($this->definition);

        if ($name === '' || $errors !== []) {
            Notification::make()->title('The report cannot be saved')->body($name === '' ? 'Give the report a name.' : implode(' ', $errors))->danger()->send();

            return;
        }

        $attributes = ['name' => $name, 'description' => $this->description, 'is_shared' => $this->isShared, 'definition' => ReportSchema::normalize($this->definition)];

        if ($this->recordId === null) {
            $report = ReportDefinition::create(['user_id' => auth()->id()] + $attributes);
            Notification::make()->title('Report saved')->success()->send();
            $this->redirect(ReportDefinitionResource::getUrl('view', ['record' => $report]));

            return;
        }

        $this->record()->update($attributes);
        $this->loaded = null;
        $this->dirty = false;
        Notification::make()->title('Report saved')->success()->send();
    }

    protected function getFooterWidgets(): array
    {
        return $this->recordId === null ? [] : [ReportRunsWidget::class];
    }

    public function getWidgetData(): array
    {
        return ['record' => $this->recordId === null ? null : $this->record()];
    }

    public function render(): View
    {
        $preview = $this->definition === null ? null : app(ReportPreview::class)->build($this->draft(), auth()->user());
        $this->chart = $preview['chart'] ?? null;

        return parent::render()->with($this->viewModel($preview));
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * @param  Closure(array<string, mixed>): array<string, mixed>  $change
     */
    private function edit(Closure $change, ?string $relation = null): void
    {
        if ($this->definition === null) {
            return;
        }

        // A related field may only be reached through a relation the report can follow from where it is.
        if ($relation !== null && ! array_key_exists($relation, app(ReportSchema::class)->relationOptions($this->definition, count($this->definition['joins'])))) {
            return;
        }

        $this->definition = ReportSchema::normalize($change($this->definition));
        $this->dirty = true;
    }

    private function editor(): DefinitionEditor
    {
        return app(DefinitionEditor::class);
    }

    /** Loaded once per request through the resource query, so visibility is re-checked every request. */
    private function record(): ReportDefinition
    {
        return $this->loaded ??= ReportDefinition::query()->visibleTo(auth()->user())->findOrFail($this->recordId);
    }

    private function canSave(): bool
    {
        return $this->recordId === null
            ? auth()->user()->can('create', ReportDefinition::class)
            : auth()->user()->can('update', $this->record());
    }

    /** The report as currently on screen, saved or not, without touching the stored one. */
    private function draft(): ReportDefinition
    {
        $draft = $this->recordId === null ? new ReportDefinition(['user_id' => auth()->id()]) : $this->record()->replicate();
        $draft->name = $this->name;
        $draft->definition = $this->definition;

        return $draft;
    }

    /**
     * Exports read the saved report, so its history and stored files match what was saved.
     */
    private function run(): ?ReportRun
    {
        if ($this->dirty) {
            Notification::make()->title('Save the report first')->body('Exports use the saved report.')->warning()->send();

            return null;
        }

        try {
            return Panorama::run(new DefinedReport($this->record()), $this->runParams(), auth()->user());
        } catch (PanoramaException|ValidationException $exception) {
            Notification::make()->title('This report cannot run')->body($exception->getMessage())->danger()->send();

            return null;
        }
    }

    /**
     * @return array<string, string>
     */
    private function runParams(): array
    {
        return $this->definition['parameters'] === [] ? [] : DateRange::from($this->definition['date_range'])->params();
    }

    /** CSV and JSON stream one row at a time, so they are downloaded straight away. */
    private function download(string $format): ?StreamedResponse
    {
        return $this->run()?->render($format)->download();
    }

    /** XLSX and HTML are built whole, so a worker builds them. */
    private function export(string $format): void
    {
        if ($this->run() === null) {
            return;
        }

        RunDefinedReportJob::dispatch($this->recordId, $this->runParams(), auth()->id(), $format);

        Notification::make()
            ->title(strtoupper($format).' export queued')
            ->body('You will be notified when it is ready to download.')
            ->success()
            ->send();
    }

    /**
     * @param  array<string, mixed>|null  $preview
     * @return array<string, mixed>
     */
    private function viewModel(?array $preview): array
    {
        $schema = app(ReportSchema::class);

        if ($this->definition === null) {
            $options = $schema->datasetOptions();

            return [
                'sections' => collect(self::SECTIONS)
                    ->map(fn (array $names): array => collect($names)->filter(fn (string $name): bool => isset($options[$name]))->mapWithKeys(fn (string $name): array => [$name => $options[$name]])->all())
                    ->filter()
                    ->all(),
            ];
        }

        $definition = $this->definition;
        $editor = $this->editor();
        $dimensions = $editor->dimensions($definition);
        $grouped = $definition['aggregates'] !== [];
        $columnsByField = collect($definition['columns'])->keyBy('field');
        $label = fn (string $field): string => $editor->columnLabel($definition, $columnsByField[$field] ?? ['field' => $field]);

        return [
            'preview' => $preview,
            'canSave' => $this->canSave(),
            'groups' => $editor->fieldGroups($definition),
            'baseLabel' => Str::headline($definition['base']),
            // Metrics by the field they total; `null` for "Count of rows".
            'selectedMetrics' => collect($definition['aggregates'])->groupBy(fn (array $aggregate): string => $aggregate['field'] ?? '')
                ->map(fn ($aggregates) => $aggregates->map(fn (array $aggregate): array => ['as' => $aggregate['as'], 'fn' => $aggregate['fn']])->values()->all())
                ->all(),
            // Keyed by the plain field, so a date grouped by month still marks its field in the list.
            'selectedDimensions' => collect($dimensions)->mapWithKeys(fn (string $field): array => [DefinitionEditor::unbucketed($field) => $field])->all(),
            'dimensions' => array_map(fn (string $field): array => [
                'field' => $field,
                'label' => $label($field),
                'customLabel' => $columnsByField[$field]['label'] ?? null,
                'format' => $columnsByField[$field]['format'] ?? 'text',
                'bucket' => DefinitionEditor::bucketOf($field),
                'date' => DefinitionEditor::bucketOf($field) !== null || in_array($schema->field($definition, $field)?->type?->value, ['date', 'datetime'], true),
            ], $dimensions),
            'metrics' => array_map(fn (array $aggregate): array => [
                'as' => $aggregate['as'],
                'fn' => $aggregate['fn'],
                'field' => $aggregate['field'],
                'label' => $label($aggregate['as']),
                'customLabel' => $columnsByField[$aggregate['as']]['label'] ?? null,
                'format' => $columnsByField[$aggregate['as']]['format'] ?? 'number',
            ], $definition['aggregates']),
            'filters' => [
                ...array_map(fn (array $filter, int $index): array => ['list' => 'filters', 'index' => $index, 'text' => $this->filterText($filter['field'], $filter['operator'], $filter['value'] ?? null)], $definition['filters'], array_keys($definition['filters'])),
                ...array_map(fn (array $having, int $index): array => ['list' => 'having', 'index' => $index, 'text' => $this->filterText($label($having['column']), $having['operator'], $having['value'])], $definition['having'], array_keys($definition['having'])),
            ],
            'filterFields' => [
                ...$schema->fieldOptions($definition, fn ($field): bool => DefinitionEditor::bucketOf($field->name) === null),
                ...($grouped && $dimensions !== [] ? collect($definition['aggregates'])->mapWithKeys(fn (array $aggregate): array => [$aggregate['as'] => $label($aggregate['as']).' (metric)'])->all() : []),
            ],
            'columnFields' => array_column($definition['columns'], 'field'),
            'order' => $definition['order_by'][0] ?? null,
            'dated' => isset($schema->parameterOptions($definition)['from']),
            'range' => $definition['date_range'],
            'presets' => DateRange::PRESETS,
            'visualizations' => $this->visualizations($grouped, $dimensions !== []),
            'visualization' => $definition['visualization'],
            'limit' => $definition['limit'],
            'operators' => ReportSchema::OPERATORS,
            'aggregateLabels' => ['sum' => 'Sum', 'avg' => 'Average', 'min' => 'Min', 'max' => 'Max', 'count' => 'Count', 'countDistinct' => 'Count distinct'],
            'formats' => ReportSchema::FORMATS,
        ];
    }

    /**
     * @return list<array{type: string, label: string, icon: Heroicon, enabled: bool, hint: string|null}>
     */
    private function visualizations(bool $hasMetrics, bool $hasDimensions): array
    {
        $chart = fn (string $type, string $label, Heroicon $icon): array => [
            'type' => $type, 'label' => $label, 'icon' => $icon,
            'enabled' => $hasMetrics && $hasDimensions,
            'hint' => $hasMetrics && $hasDimensions ? null : 'Add a dimension and a metric',
        ];

        return [
            ['type' => 'table', 'label' => 'Table', 'icon' => Heroicon::OutlinedTableCells, 'enabled' => true, 'hint' => null],
            $chart('bar', 'Bar', Heroicon::OutlinedChartBar),
            $chart('horizontal_bar', 'Horizontal bar', Heroicon::OutlinedBars3BottomLeft),
            $chart('line', 'Line', Heroicon::OutlinedPresentationChartLine),
            $chart('area', 'Area', Heroicon::OutlinedChartBarSquare),
            $chart('pie', 'Pie', Heroicon::OutlinedChartPie),
            $chart('donut', 'Donut', Heroicon::OutlinedStop),
            ['type' => 'kpi', 'label' => 'Number', 'icon' => Heroicon::OutlinedHashtag, 'enabled' => $hasMetrics && ! $hasDimensions, 'hint' => $hasMetrics && ! $hasDimensions ? null : 'Metrics only, no dimensions'],
        ];
    }

    private function filterText(string $field, string $operator, mixed $value): string
    {
        $value = is_array($value) ? implode(', ', $value) : (string) $value;

        return trim(Str::headline(Str::afterLast($field, '.'))." {$operator} {$value}");
    }
}
