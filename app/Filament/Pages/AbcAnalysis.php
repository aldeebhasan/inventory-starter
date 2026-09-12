<?php

namespace App\Filament\Pages;

use Aldeebhasan\Inventorix\Enums\MovementType;
use Aldeebhasan\Inventorix\Models\Movement;
use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
use BackedEnum;
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

    protected static string|UnitEnum|null $navigationGroup = 'Inventory Reports';

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

    /** @var Collection<int, array{consumption_qty: float, consumption_value: float, cumulative_pct: float, abc_class: string}>|null */
    protected ?Collection $abcCache = null;

    protected function getDateFrom(): string
    {
        return $this->tableFilters['date_range']['from'] ?? now()->subYear()->toDateString();
    }

    protected function getDateUntil(): string
    {
        return $this->tableFilters['date_range']['until'] ?? now()->toDateString();
    }

    protected function getAbcData(): Collection
    {
        if ($this->abcCache !== null) {
            return $this->abcCache;
        }

        $query = Movement::query()
            ->whereHasMorph('stockable', [Product::class])
            ->where('type', MovementType::Deduct)
            ->whereBetween('created_at', [$this->getDateFrom().' 00:00:00', $this->getDateUntil().' 23:59:59']);

        $locationId = $this->tableFilters['location_id']['value'] ?? null;
        if ($locationId) {
            $query->where('location_id', $locationId);
        }

        $consumption = $query
            ->selectRaw('stockable_id, SUM(quantity) as total_qty')
            ->groupBy('stockable_id')
            ->get();

        $products = Product::whereIn('id', $consumption->pluck('stockable_id'))
            ->get()
            ->keyBy('id');

        // Filter by category if set
        $categoryId = $this->tableFilters['category']['value'] ?? null;
        if ($categoryId) {
            $productIdsInCategory = Product::whereHas('categories', fn ($c) => $c->where('categories.id', $categoryId))
                ->pluck('id');
            $consumption = $consumption->filter(fn ($row) => $productIdsInCategory->contains($row->stockable_id));
        }

        $items = $consumption->map(function ($row) use ($products) {
            $product = $products->get($row->stockable_id);
            $cost = (float) ($product?->cost ?? 0);

            return [
                'product_id' => $row->stockable_id,
                'consumption_qty' => (float) $row->total_qty,
                'consumption_value' => (float) $row->total_qty * $cost,
                'avg_cost' => $cost,
            ];
        })->sortByDesc('consumption_value')->values();

        $grandTotal = $items->sum('consumption_value');
        $runningTotal = 0;

        $this->abcCache = $items->mapWithKeys(function ($item) use ($grandTotal, &$runningTotal) {
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

        return $this->abcCache;
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
                    ->options(fn () => Location::query()->where('is_active', true)->pluck('name', 'id'))
                    ->searchable()
                    ->query(fn (Builder $query, array $data): Builder => $query),

                SelectFilter::make('category')
                    ->label('Category')
                    ->options(fn () => Category::query()->pluck('name', 'id'))
                    ->searchable()
                    ->query(fn (Builder $query, array $data): Builder => $query),
            ])
            ->paginated([25, 50, 100]);
    }
}
