<?php

namespace App\Filament\Pages;

use Aldeebhasan\Inventorix\Enums\MovementType;
use App\Filament\Exports\AbcAnalysisExporter;
use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
use BackedEnum;
use Filament\Actions\ExportAction;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use UnitEnum;

class AbcAnalysis extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.abc-analysis';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartPie;

    protected static string|UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?string $navigationParentItem = 'Reports';

    protected static ?int $navigationSort = 17;

    protected static ?string $navigationLabel = 'ABC Analysis';

    public function getTitle(): string|Htmlable
    {
        return 'ABC Analysis';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Classify products by consumption value — A items (top 80%), B (next 15%), C (remaining 5%) — to focus inventory control efforts.';
    }

    /** @var Collection<int, array{consumption_qty: float, consumption_value: float, avg_cost: float, cumulative_pct: float|int, abc_class: string}>|null */
    protected ?Collection $abcCache = null;

    protected function getDateFrom(): string
    {
        return ($this->tableFilters['date_range']['from'] ?? null) ?: now()->subYear()->toDateString();
    }

    protected function getDateUntil(): string
    {
        return ($this->tableFilters['date_range']['until'] ?? null) ?: now()->toDateString();
    }

    /**
     * Current filter values, also passed to {@see AbcAnalysisExporter} as export options.
     *
     * @return array{from: string, until: string, location_id: mixed, category: mixed}
     */
    protected function getAbcOptions(): array
    {
        return [
            'from' => $this->getDateFrom(),
            'until' => $this->getDateUntil(),
            'location_id' => $this->tableFilters['location_id']['value'] ?? null,
            'category' => $this->tableFilters['category']['value'] ?? null,
        ];
    }

    /**
     * @return Collection<int, array{consumption_qty: float, consumption_value: float, avg_cost: float, cumulative_pct: float|int, abc_class: string}>
     */
    protected function getAbcData(): Collection
    {
        return $this->abcCache ??= static::computeAbc($this->getAbcOptions());
    }

    /**
     * Rank all consumed products by consumption value. The cumulative % needs every product's
     * consumption, so this aggregates in one SQL query (one row per consumed product, no models)
     * and ranks in PHP. Keyed by product id, in rank order.
     *
     * @param  array{from?: string|null, until?: string|null, location_id?: mixed, category?: mixed}  $options
     * @return Collection<int, array{consumption_qty: float, consumption_value: float, avg_cost: float, cumulative_pct: float|int, abc_class: string}>
     */
    public static function computeAbc(array $options): Collection
    {
        $from = ($options['from'] ?? null) ?: now()->subYear()->toDateString();
        $until = ($options['until'] ?? null) ?: now()->toDateString();
        $locationId = $options['location_id'] ?? null;
        $categoryId = $options['category'] ?? null;

        $items = Product::query()
            ->join('inventorix_movements', function ($join): void {
                $join->on('inventorix_movements.stockable_id', '=', 'products.id')
                    ->where('inventorix_movements.stockable_type', Product::class);
            })
            ->where('inventorix_movements.type', MovementType::Deduct->value)
            ->whereBetween('inventorix_movements.created_at', [$from.' 00:00:00', $until.' 23:59:59'])
            ->when(filled($locationId), fn (Builder $q) => $q->where('inventorix_movements.location_id', $locationId))
            ->when(filled($categoryId), fn (Builder $q) => $q->whereHas('categories', fn ($c) => $c->where('categories.id', $categoryId)))
            ->groupBy('products.id', 'products.cost')
            ->orderBy('products.id')
            ->selectRaw('products.id as product_id, products.cost as cost, SUM(inventorix_movements.quantity) as total_qty')
            ->toBase()
            ->get()
            ->map(fn (object $row): array => [
                'product_id' => (int) $row->product_id,
                'consumption_qty' => (float) $row->total_qty,
                'consumption_value' => (float) $row->total_qty * (float) ($row->cost ?? 0),
                'avg_cost' => (float) ($row->cost ?? 0),
            ])
            ->sortByDesc('consumption_value')
            ->values();

        $grandTotal = $items->sum('consumption_value');
        $runningTotal = 0;

        return $items->mapWithKeys(function (array $item) use ($grandTotal, &$runningTotal): array {
            $runningTotal += $item['consumption_value'];
            $cumulativePct = $grandTotal > 0 ? ($runningTotal / $grandTotal) * 100 : 0;

            $class = 'C';
            if ($cumulativePct <= 80) {
                $class = 'A';
            } elseif ($cumulativePct <= 95) {
                $class = 'B';
            }

            return [
                $item['product_id'] => [
                    'consumption_qty' => $item['consumption_qty'],
                    'consumption_value' => $item['consumption_value'],
                    'avg_cost' => $item['avg_cost'],
                    'cumulative_pct' => $cumulativePct,
                    'abc_class' => $class,
                ],
            ];
        });
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Product::query()
                    ->with(['brand'])
            )
            ->modifyQueryUsing(function (Builder $query): Builder {
                $abcData = $this->getAbcData();
                if ($abcData->isEmpty()) {
                    return $query->whereRaw('1 = 0');
                }

                $ids = $abcData->keys()->toArray();
                $cases = collect($ids)->map(fn ($id, $i) => "WHEN {$id} THEN {$i}")->implode(' ');

                return $query->whereIn('id', $ids)
                    ->orderByRaw("CASE id {$cases} END");
            })
            ->columns([
                TextColumn::make('rank')
                    ->label('#')
                    ->rowIndex(),

                TextColumn::make('name')
                    ->label('Product')
                    ->searchable(),

                TextColumn::make('brand.name')
                    ->label('Brand')
                    ->placeholder('—'),

                TextColumn::make('consumption_qty')
                    ->label('Consumption Qty')
                    ->state(fn (Product $record): string => number_format(
                        $this->getAbcData()->get($record->id)['consumption_qty'] ?? 0, 3
                    )),

                TextColumn::make('avg_cost')
                    ->label('Avg Cost/Unit')
                    ->state(fn (Product $record): string => number_format(
                        $this->getAbcData()->get($record->id)['avg_cost'] ?? 0, 3
                    )),

                TextColumn::make('consumption_value')
                    ->label('Consumption Value')
                    ->state(fn (Product $record): string => number_format(
                        $this->getAbcData()->get($record->id)['consumption_value'] ?? 0, 2
                    ))
                    ->money('USD'),

                TextColumn::make('cumulative_pct')
                    ->label('Cumulative %')
                    ->state(fn (Product $record): string => number_format(
                        $this->getAbcData()->get($record->id)['cumulative_pct'] ?? 0, 1
                    ).'%'),

                TextColumn::make('abc_class')
                    ->label('ABC Class')
                    ->badge()
                    ->state(fn (Product $record): string => $this->getAbcData()->get($record->id)['abc_class'] ?? 'C')
                    ->color(fn (string $state): string => match ($state) {
                        'A' => 'success',
                        'B' => 'warning',
                        default => 'gray',
                    }),
            ])
            ->filters([
                Filter::make('date_range')
                    ->label('Date Range')
                    ->form([
                        DatePicker::make('from')
                            ->label('From')
                            ->default(now()->subYear()),
                        DatePicker::make('until')
                            ->label('Until')
                            ->default(now()),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query)
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($data['from'] ?? null) {
                            $indicators[] = 'From: '.$data['from'];
                        }
                        if ($data['until'] ?? null) {
                            $indicators[] = 'Until: '.$data['until'];
                        }

                        return $indicators;
                    }),

                SelectFilter::make('location_id')
                    ->label('Location')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => Location::query()->where('is_active', true)->where('name', 'like', "%{$search}%")->limit(50)->pluck('name', 'id'))
                    ->getOptionLabelUsing(fn ($value) => Location::find($value)?->name)
                    ->query(fn (Builder $query, array $data): Builder => $query),

                SelectFilter::make('category')
                    ->label('Category')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => Category::query()->where('name', 'like', "%{$search}%")->limit(50)->pluck('name', 'id'))
                    ->getOptionLabelUsing(fn ($value) => Category::find($value)?->name)
                    ->query(fn (Builder $query, array $data): Builder => $query),
            ])
            ->headerActions([
                ExportAction::make()
                    ->exporter(AbcAnalysisExporter::class)
                    ->options(fn (): array => $this->getAbcOptions()),
            ])
            ->paginated([25, 50, 100]);
    }
}
