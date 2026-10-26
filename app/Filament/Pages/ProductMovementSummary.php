<?php

namespace App\Filament\Pages;

use Aldeebhasan\Inventorix\Enums\MovementType;
use Aldeebhasan\Inventorix\Models\Movement;
use Aldeebhasan\Inventorix\Models\Stock;
use App\Filament\Exports\ProductMovementSummaryExporter;
use App\Models\Brand;
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

    protected function getDateFrom(): string
    {
        return ($this->tableFilters['date_range']['from'] ?? null) ?: now()->subDays(30)->toDateString();
    }

    protected function getDateUntil(): string
    {
        return ($this->tableFilters['date_range']['until'] ?? null) ?: now()->toDateString();
    }

    protected function getLocationFilter(): ?int
    {
        $val = $this->tableFilters['location_id']['value'] ?? null;

        return $val ? (int) $val : null;
    }

    /**
     * Movements of the outer product row within the date range (and location, if set).
     *
     * @return Builder<Movement>
     */
    protected static function movementsInRange(string $from, string $until, ?int $locationId): Builder
    {
        return Movement::query()
            ->whereColumn('inventorix_movements.stockable_id', 'products.id')
            ->where('inventorix_movements.stockable_type', Product::class)
            ->whereBetween('inventorix_movements.created_at', [$from.' 00:00:00', $until.' 23:59:59'])
            ->when($locationId, fn (Builder $q) => $q->where('inventorix_movements.location_id', $locationId));
    }

    /**
     * Products that moved in the range, with `total_added`, `total_deducted` and `current_stock`
     * selected as correlated subqueries so only the returned rows are aggregated.
     * Shared with {@see ProductMovementSummaryExporter}.
     *
     * @return Builder<Product>
     */
    public static function getReportQuery(string $from, string $until, ?int $locationId): Builder
    {
        $sumOfType = fn (MovementType $type) => static::movementsInRange($from, $until, $locationId)
            ->where('inventorix_movements.type', $type->value)
            ->selectRaw('COALESCE(SUM(inventorix_movements.quantity), 0)');

        return Product::query()
            ->select('products.*')
            ->addSelect([
                'total_added' => $sumOfType(MovementType::Add),
                'total_deducted' => $sumOfType(MovementType::Deduct),
                'current_stock' => Stock::query()
                    ->whereColumn('inventorix_stocks.stockable_id', 'products.id')
                    ->where('inventorix_stocks.stockable_type', Product::class)
                    ->selectRaw('COALESCE(SUM(inventorix_stocks.quantity), 0)'),
            ])
            ->with(['brand', 'categories'])
            ->whereExists(static::movementsInRange($from, $until, $locationId)->toBase());
    }

    public static function netChange(Product $record): float
    {
        return (float) $record->getAttribute('total_added') - (float) $record->getAttribute('total_deducted');
    }

    public static function turnoverRate(Product $record): string
    {
        $currentQty = (float) $record->getAttribute('current_stock');

        return $currentQty > 0
            ? number_format((float) $record->getAttribute('total_deducted') / $currentQty, 2)
            : '—';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => static::getReportQuery($this->getDateFrom(), $this->getDateUntil(), $this->getLocationFilter()))
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
                    ->state(fn (Product $record): string => number_format((float) $record->getAttribute('total_added'), 3)),

                TextColumn::make('total_deducted')
                    ->label('Total Deducted')
                    ->state(fn (Product $record): string => number_format((float) $record->getAttribute('total_deducted'), 3)),

                TextColumn::make('net_change')
                    ->label('Net Change')
                    ->badge()
                    ->state(fn (Product $record): string => number_format(static::netChange($record), 3))
                    ->color(fn (Product $record): string => match (true) {
                        static::netChange($record) > 0 => 'success',
                        static::netChange($record) < 0 => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('current_stock')
                    ->label('Current Stock')
                    ->state(fn (Product $record): string => number_format((float) $record->getAttribute('current_stock'), 2)),

                TextColumn::make('turnover_rate')
                    ->label('Turnover Rate')
                    ->state(fn (Product $record): string => static::turnoverRate($record)),
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
                    // Applied in getReportQuery() via the table query.
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
                    // Applied in getReportQuery() via the table query.
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
            ->headerActions([
                ExportAction::make()
                    ->exporter(ProductMovementSummaryExporter::class),
            ])
            ->defaultSort('name');
    }
}
