<?php

namespace App\Filament\Pages;

use Aldeebhasan\Inventorix\Enums\MovementType;
use Aldeebhasan\Inventorix\Models\Movement;
use Aldeebhasan\Inventorix\Models\Stock;
use App\Filament\Exports\StockVelocityReportExporter;
use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
use BackedEnum;
use Filament\Actions\ExportAction;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class StockVelocityReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.stock-velocity-report';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    protected static string|UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?string $navigationParentItem = 'Reports';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Stock Velocity';

    public function getTitle(): string|Htmlable
    {
        return 'Stock Velocity & Runway';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'How fast each product is consumed and how many days of stock remain at current usage rates.';
    }

    protected function getPeriod(): int
    {
        $filter = $this->tableFilters['period']['value'] ?? null;

        return $filter ? (int) $filter : 30;
    }

    /**
     * Deduct movements of the outer stock row (same stockable + location) within the period.
     *
     * @return Builder<Movement>
     */
    protected static function deductsInPeriod(int $period): Builder
    {
        return Movement::query()
            ->whereColumn('inventorix_movements.stockable_id', 'inventorix_stocks.stockable_id')
            ->whereColumn('inventorix_movements.stockable_type', 'inventorix_stocks.stockable_type')
            ->whereColumn('inventorix_movements.location_id', 'inventorix_stocks.location_id')
            ->where('inventorix_movements.type', MovementType::Deduct->value)
            ->where('inventorix_movements.created_at', '>=', now()->subDays($period)->toDateTimeString());
    }

    /**
     * Stocks with `total_deducted` and `peak_demand_date` selected as correlated subqueries,
     * so only the returned rows are aggregated. Shared with {@see StockVelocityReportExporter}.
     *
     * @return Builder<Stock>
     */
    public static function getReportQuery(int $period): Builder
    {
        return Stock::query()
            ->select('inventorix_stocks.*')
            ->addSelect([
                'total_deducted' => static::deductsInPeriod($period)->selectRaw('COALESCE(SUM(inventorix_movements.quantity), 0)'),
                'peak_demand_date' => static::deductsInPeriod($period)
                    ->selectRaw('DATE(inventorix_movements.created_at)')
                    ->groupByRaw('DATE(inventorix_movements.created_at)')
                    ->orderByRaw('SUM(inventorix_movements.quantity) DESC')
                    ->limit(1),
            ])
            ->with(['stockable', 'location'])
            ->whereHasMorph('stockable', [Product::class])
            ->where('quantity', '>', 0);
    }

    /**
     * Restrict to a velocity status in SQL. Days of stock = available / (deducted / period),
     * so "days < N" is rewritten as "available * period < N * deducted" (no division).
     *
     * @param  Builder<Stock>  $query
     * @return Builder<Stock>
     */
    public static function applyStatusFilter(Builder $query, string $status, int $period): Builder
    {
        $deducted = static::deductsInPeriod($period)->selectRaw('COALESCE(SUM(inventorix_movements.quantity), 0)')->toBase();
        $sql = '('.$deducted->toSql().')';
        $available = '(inventorix_stocks.quantity - inventorix_stocks.reserved_quantity) * ?';

        return match ($status) {
            'critical' => $query->whereRaw("{$sql} > 0 AND {$available} < 7 * {$sql}", [...$deducted->getBindings(), $period, ...$deducted->getBindings()]),
            'warning' => $query->whereRaw("{$sql} > 0 AND {$available} >= 7 * {$sql} AND {$available} < 14 * {$sql}", [...$deducted->getBindings(), $period, ...$deducted->getBindings(), $period, ...$deducted->getBindings()]),
            'healthy' => $query->whereRaw("({$sql} <= 0 OR {$available} >= 14 * {$sql})", [...$deducted->getBindings(), $period, ...$deducted->getBindings()]),
            default => $query,
        };
    }

    public static function available(Stock $record): float
    {
        return (float) $record->quantity - (float) $record->reserved_quantity;
    }

    public static function avgDailyConsumption(Stock $record, int $period): float
    {
        return (float) $record->getAttribute('total_deducted') / max($period, 1);
    }

    public static function daysOfStock(Stock $record, int $period): ?float
    {
        $avgDaily = static::avgDailyConsumption($record, $period);

        return $avgDaily > 0 ? static::available($record) / $avgDaily : null;
    }

    public static function formatDaysOfStock(?float $days): string
    {
        return $days === null ? '∞' : (string) (int) $days;
    }

    public static function status(?float $days): string
    {
        return match (true) {
            $days === null => 'Healthy',
            $days < 7 => 'Critical',
            $days < 14 => 'Warning',
            default => 'Healthy',
        };
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => static::getReportQuery($this->getPeriod()))
            ->columns([
                TextColumn::make('stockable.name')
                    ->label('Product')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHasMorph(
                            'stockable',
                            [Product::class],
                            fn (Builder $q) => $q->where('name', 'like', "%{$search}%")
                        );
                    })
                    // stockable is a morphTo, so sort by the product name through a subquery.
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy(
                        Product::query()->select('name')->whereColumn('products.id', 'inventorix_stocks.stockable_id')->limit(1),
                        $direction,
                    )),

                TextColumn::make('location.name')
                    ->label('Location')
                    ->sortable(),

                TextColumn::make('quantity')
                    ->label('On Hand')
                    ->numeric(decimalPlaces: 2)
                    ->sortable(),

                TextColumn::make('available')
                    ->label('Available')
                    ->numeric(decimalPlaces: 2)
                    ->state(fn (Stock $record): float => static::available($record)),

                TextColumn::make('avg_daily_consumption')
                    ->label('Avg Daily Consumption')
                    ->state(fn (Stock $record): string => number_format(static::avgDailyConsumption($record, $this->getPeriod()), 2)),

                TextColumn::make('days_of_stock')
                    ->label('Days of Stock')
                    ->state(fn (Stock $record): string => static::formatDaysOfStock(static::daysOfStock($record, $this->getPeriod()))),

                TextColumn::make('peak_demand_date')
                    ->label('Peak Demand Day')
                    ->placeholder('—'),

                TextColumn::make('velocity_status')
                    ->label('Status')
                    ->badge()
                    ->state(fn (Stock $record): string => static::status(static::daysOfStock($record, $this->getPeriod())))
                    ->color(fn (string $state): string => match ($state) {
                        'Critical' => 'danger',
                        'Warning' => 'warning',
                        default => 'success',
                    }),
            ])
            ->filters([
                SelectFilter::make('location_id')
                    ->label('Location')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => Location::query()->where('is_active', true)->where('name', 'like', "%{$search}%")->limit(50)->pluck('name', 'id'))
                    ->getOptionLabelUsing(fn ($value) => Location::find($value)?->name),

                SelectFilter::make('category')
                    ->label('Category')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => Category::query()->where('name', 'like', "%{$search}%")->limit(50)->pluck('name', 'id'))
                    ->getOptionLabelUsing(fn ($value) => Category::find($value)?->name)
                    ->query(function (Builder $query, array $data): Builder {
                        if (blank($data['value'])) {
                            return $query;
                        }

                        return $query->whereHasMorph('stockable', [Product::class],
                            fn (Builder $q) => $q->whereHas('categories', fn ($c) => $c->where('categories.id', $data['value']))
                        );
                    }),

                SelectFilter::make('period')
                    ->label('Period')
                    ->options([
                        '7' => '7 days',
                        '14' => '14 days',
                        '30' => '30 days',
                        '60' => '60 days',
                        '90' => '90 days',
                    ])
                    ->default('30')
                    // Applied in getReportQuery() via the table query.
                    ->query(fn (Builder $query, array $data): Builder => $query),

                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'critical' => 'Critical (<7d)',
                        'warning' => 'Warning (<14d)',
                        'healthy' => 'Healthy',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => blank($data['value'])
                        ? $query
                        : static::applyStatusFilter($query, $data['value'], $this->getPeriod())),
            ])
            ->headerActions([
                ExportAction::make()
                    ->exporter(StockVelocityReportExporter::class)
                    ->options(fn (): array => ['period' => $this->getPeriod()]),
            ])
            ->defaultSort('quantity', 'asc');
    }
}
