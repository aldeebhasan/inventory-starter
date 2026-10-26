<?php

namespace App\Filament\Pages;

use Aldeebhasan\Inventorix\Enums\MovementType;
use Aldeebhasan\Inventorix\Models\Movement;
use Aldeebhasan\Inventorix\Models\Stock;
use App\Models\Brand;
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

class ProductMovementSummary extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.product-movement-summary';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowTrendingUp;

    protected static string|UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?string $navigationParentItem = 'Reports';

    protected static ?int $navigationSort = 11;

    protected static ?string $navigationLabel = 'Movement Summary';

    public function getTitle(): string|Htmlable
    {
        return 'Product Movement Summary';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Aggregated stock additions and deductions per product over a date range, with net change and turnover rate.';
    }

    /** @var Collection<int|string, Movement>|null */
    protected ?Collection $movementCache = null;

    /** @var Collection<int|string, Stock>|null */
    protected ?Collection $stockCache = null;

    protected function getDateFrom(): string
    {
        return $this->tableFilters['date_range']['from'] ?? now()->subDays(30)->toDateString();
    }

    protected function getDateUntil(): string
    {
        return $this->tableFilters['date_range']['until'] ?? now()->toDateString();
    }

    protected function getLocationFilter(): ?int
    {
        $val = $this->tableFilters['location_id']['value'] ?? null;

        return $val ? (int) $val : null;
    }

    protected function getMovementAggregates(): Collection
    {
        if ($this->movementCache !== null) {
            return $this->movementCache;
        }

        $query = Movement::query()
            ->whereHasMorph('stockable', [Product::class])
            ->whereBetween('created_at', [$this->getDateFrom().' 00:00:00', $this->getDateUntil().' 23:59:59']);

        $locationId = $this->getLocationFilter();
        if ($locationId) {
            $query->where('location_id', $locationId);
        }

        $this->movementCache = $query
            ->selectRaw('
                stockable_id,
                SUM(CASE WHEN type = ? THEN quantity ELSE 0 END) as total_added,
                SUM(CASE WHEN type = ? THEN quantity ELSE 0 END) as total_deducted
            ', [MovementType::Add->value, MovementType::Deduct->value])
            ->groupBy('stockable_id')
            ->get()
            ->keyBy('stockable_id');

        return $this->movementCache;
    }

    protected function getCurrentStocks(): Collection
    {
        if ($this->stockCache !== null) {
            return $this->stockCache;
        }

        $this->stockCache = Stock::query()
            ->whereHasMorph('stockable', [Product::class])
            ->selectRaw('stockable_id, SUM(quantity) as total_qty')
            ->groupBy('stockable_id')
            ->get()
            ->keyBy('stockable_id');

        return $this->stockCache;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Product::query()
                    ->with(['brand', 'categories'])
                    ->whereHas('stocks', function ($q) {
                        // Only products that have movements in the date range
                    })
            )
            ->columns([
                TextColumn::make('name')
                    ->label('Product')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('brand.name')
                    ->label('Brand')
                    ->placeholder('—'),

                TextColumn::make('category')
                    ->label('Category')
                    ->state(fn (Product $record): string => $record->categories->first()->name ?? '—'),

                TextColumn::make('total_added')
                    ->label('Total Added')
                    ->state(function (Product $record): string {
                        $agg = $this->getMovementAggregates()->get($record->id);

                        return number_format((float) ($agg?->getAttribute('total_added') ?? 0), 3);
                    }),

                TextColumn::make('total_deducted')
                    ->label('Total Deducted')
                    ->state(function (Product $record): string {
                        $agg = $this->getMovementAggregates()->get($record->id);

                        return number_format((float) ($agg?->getAttribute('total_deducted') ?? 0), 3);
                    }),

                TextColumn::make('net_change')
                    ->label('Net Change')
                    ->badge()
                    ->state(function (Product $record): string {
                        $agg = $this->getMovementAggregates()->get($record->id);
                        $added = (float) ($agg?->getAttribute('total_added') ?? 0);
                        $deducted = (float) ($agg?->getAttribute('total_deducted') ?? 0);

                        return number_format($added - $deducted, 3);
                    })
                    ->color(function (Product $record): string {
                        $agg = $this->getMovementAggregates()->get($record->id);
                        $added = (float) ($agg?->getAttribute('total_added') ?? 0);
                        $deducted = (float) ($agg?->getAttribute('total_deducted') ?? 0);
                        $net = $added - $deducted;

                        if ($net > 0) {
                            return 'success';
                        }
                        if ($net < 0) {
                            return 'danger';
                        }

                        return 'gray';
                    }),

                TextColumn::make('current_stock')
                    ->label('Current Stock')
                    ->state(function (Product $record): string {
                        $stock = $this->getCurrentStocks()->get($record->id);

                        return number_format((float) ($stock?->getAttribute('total_qty') ?? 0), 2);
                    }),

                TextColumn::make('turnover_rate')
                    ->label('Turnover Rate')
                    ->state(function (Product $record): string {
                        $agg = $this->getMovementAggregates()->get($record->id);
                        $deducted = (float) ($agg?->getAttribute('total_deducted') ?? 0);
                        $stock = $this->getCurrentStocks()->get($record->id);
                        $currentQty = (float) ($stock?->getAttribute('total_qty') ?? 0);

                        if ($currentQty <= 0) {
                            return '—';
                        }

                        return number_format($deducted / $currentQty, 2);
                    }),
            ])
            ->filters([
                Filter::make('date_range')
                    ->label('Date Range')
                    ->form([
                        DatePicker::make('from')
                            ->label('From')
                            ->default(now()->subDays(30)),
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
                    ->query(function (Builder $query, array $data): Builder {
                        if (blank($data['value'])) {
                            return $query;
                        }

                        return $query->whereHas('categories', fn ($c) => $c->where('categories.id', $data['value']));
                    }),

                SelectFilter::make('brand')
                    ->label('Brand')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => Brand::query()->where('name', 'like', "%{$search}%")->limit(50)->pluck('name', 'id'))
                    ->getOptionLabelUsing(fn ($value) => Brand::find($value)?->name)
                    ->query(function (Builder $query, array $data): Builder {
                        if (blank($data['value'])) {
                            return $query;
                        }

                        return $query->where('brand_id', $data['value']);
                    }),
            ])
            ->defaultSort('name');
    }
}
