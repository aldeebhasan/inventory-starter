<?php

namespace App\Filament\Pages;

use Aldeebhasan\Inventorix\Enums\MovementType;
use Aldeebhasan\Inventorix\Models\Movement;
use Aldeebhasan\Inventorix\Models\Stock;
use Aldeebhasan\Inventorix\Models\Threshold;
use App\Filament\Exports\ReorderSuggestionsExporter;
use App\Filament\Resources\PurchaseOrders\Pages\CreatePurchaseOrder;
use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
use App\Models\Supplier;
use BackedEnum;
use Filament\Actions\Action;
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
use Illuminate\Database\Eloquent\Relations\MorphTo;
use UnitEnum;

class ReorderSuggestions extends Page implements HasTable
{
    use InteractsWithTable;

    /** Consumption velocity window, in days. */
    public const VELOCITY_DAYS = 30;

    protected string $view = 'filament.pages.reorder-suggestions';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Purchase';

    protected static ?string $navigationParentItem = 'Reports';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Reorder Suggestions';

    public function getTitle(): string|Htmlable
    {
        return 'Reorder Suggestions';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Low-stock products with suggested reorder quantities based on consumption velocity and thresholds.';
    }

    /**
     * Thresholds that apply to the outer stock row: location-specific or global (null location).
     *
     * @return Builder<Threshold>
     */
    protected static function matchingThresholds(): Builder
    {
        $stocks = (new Stock)->getTable();
        $thresholds = (new Threshold)->getTable();

        return Threshold::query()
            ->whereColumn("{$thresholds}.stockable_type", "{$stocks}.stockable_type")
            ->whereColumn("{$thresholds}.stockable_id", "{$stocks}.stockable_id")
            ->where(fn (Builder $q) => $q
                ->whereColumn("{$thresholds}.location_id", "{$stocks}.location_id")
                ->orWhereNull("{$thresholds}.location_id"));
    }

    /**
     * The applicable threshold's column, preferring the location-specific threshold over the global one.
     *
     * @return Builder<Threshold>
     */
    protected static function thresholdValue(string $column): Builder
    {
        $thresholds = (new Threshold)->getTable();

        return static::matchingThresholds()
            ->select("{$thresholds}.{$column}")
            ->orderByRaw("{$thresholds}.location_id IS NULL")
            ->limit(1);
    }

    /**
     * Aggregate over the outer stock row's deduct movements in the velocity window.
     *
     * @return Builder<Movement>
     */
    protected static function recentDeductions(string $aggregateSql): Builder
    {
        $stocks = (new Stock)->getTable();
        $movements = (new Movement)->getTable();

        return Movement::query()
            ->selectRaw($aggregateSql)
            ->whereColumn("{$movements}.stockable_type", "{$stocks}.stockable_type")
            ->whereColumn("{$movements}.stockable_id", "{$stocks}.stockable_id")
            ->whereColumn("{$movements}.location_id", "{$stocks}.location_id")
            ->where("{$movements}.type", MovementType::Deduct)
            ->where("{$movements}.created_at", '>=', now()->subDays(self::VELOCITY_DAYS));
    }

    /**
     * Days of stock remaining = available / (deducted in window / window days); NULL when nothing was consumed.
     * Same definition as getDaysUntilStockout(), evaluated in SQL so the urgency filter can use it.
     *
     * @return Builder<Movement>
     */
    protected static function daysOfStockSubquery(): Builder
    {
        $stocks = (new Stock)->getTable();
        $movements = (new Movement)->getTable();

        return static::recentDeductions(
            "({$stocks}.quantity - {$stocks}.reserved_quantity) * ".self::VELOCITY_DAYS." / NULLIF(SUM({$movements}.quantity), 0)"
        );
    }

    /**
     * Keep rows with fewer than $days days of stock left; rows with no consumption (infinite days) are excluded.
     */
    protected static function whereDaysOfStockBelow(Builder $query, int $days): Builder
    {
        $daysOfStock = static::daysOfStockSubquery();

        return $query->whereRaw("({$daysOfStock->toSql()}) < ?", [...$daysOfStock->getBindings(), $days]);
    }

    /**
     * @return Builder<Stock>
     */
    protected static function baseQuery(): Builder
    {
        $stocks = (new Stock)->getTable();
        $thresholds = (new Threshold)->getTable();
        $movements = (new Movement)->getTable();

        // Same low-stock rule as Inventorix::lowStockItems(), but kept in SQL instead of loading every low-stock ID.
        return Stock::query()
            // morphWith instead of 'stockable.suppliers': nested morph paths can't be serialized for queued exports.
            ->with([
                'stockable' => fn (MorphTo $morphTo) => $morphTo->morphWith([Product::class => ['suppliers']]),
                'location',
            ])
            ->where("{$stocks}.stockable_type", Product::class)
            ->whereExists(static::matchingThresholds()->whereColumn("{$stocks}.quantity", '<=', "{$thresholds}.min_quantity"))
            ->select("{$stocks}.*")
            ->selectSub(static::thresholdValue('min_quantity'), 'min_quantity')
            ->selectSub(static::thresholdValue('max_quantity'), 'max_quantity')
            ->selectSub(static::recentDeductions("SUM({$movements}.quantity)"), 'total_deducted');
    }

    public static function getAvailable(Stock $record): float
    {
        return (float) $record->quantity - (float) $record->reserved_quantity;
    }

    public static function getAvgDailyConsumption(Stock $record): float
    {
        $deducted = (float) $record->getAttribute('total_deducted');

        return $deducted <= 0 ? 0 : $deducted / self::VELOCITY_DAYS;
    }

    public static function getDaysUntilStockout(Stock $record): ?float
    {
        $avgDaily = static::getAvgDailyConsumption($record);

        return $avgDaily <= 0 ? null : static::getAvailable($record) / $avgDaily;
    }

    public static function getSuggestedQty(Stock $record): int
    {
        $available = static::getAvailable($record);
        $min = $record->getAttribute('min_quantity');
        $max = $record->getAttribute('max_quantity');

        $suggested = $max !== null
            ? (float) $max - $available
            : ceil(static::getAvgDailyConsumption($record) * self::VELOCITY_DAYS);

        $minDeficit = $min !== null ? (float) $min - $available : 0;

        return (int) max($suggested, $minDeficit, 0);
    }

    public static function getPreferredSupplier(Stock $record): ?Supplier
    {
        $product = $record->stockable instanceof Product ? $record->stockable : null;

        return $product?->suppliers->first();
    }

    public static function getUnitCost(Stock $record): float
    {
        $product = $record->stockable instanceof Product ? $record->stockable : null;

        return (float) (static::getPreferredSupplier($record)?->getAttribute('pivot')->unit_cost ?? $product->cost ?? 0);
    }

    public function table(Table $table): Table
    {
        $stocks = (new Stock)->getTable();

        return $table
            ->query(static::baseQuery())
            ->headerActions([
                ExportAction::make()
                    ->exporter(ReorderSuggestionsExporter::class),
            ])
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
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy(
                        Product::withTrashed()->select('name')->whereColumn('products.id', "{$stocks}.stockable_id"),
                        $direction
                    )),

                TextColumn::make('location.name')
                    ->label('Location')
                    ->sortable(),

                TextColumn::make('available')
                    ->label('Available')
                    ->numeric(decimalPlaces: 2)
                    ->state(fn (Stock $record): float => static::getAvailable($record)),

                TextColumn::make('min_threshold')
                    ->label('Min Threshold')
                    ->state(fn (Stock $record): string => $record->getAttribute('min_quantity') !== null
                        ? number_format((float) $record->getAttribute('min_quantity'), 2)
                        : '—'),

                TextColumn::make('avg_daily')
                    ->label('Avg Daily Consumption')
                    ->state(fn (Stock $record): string => number_format(static::getAvgDailyConsumption($record), 2)),

                TextColumn::make('days_until_stockout')
                    ->label('Days Until Stockout')
                    ->state(function (Stock $record): string {
                        $days = static::getDaysUntilStockout($record);

                        return $days === null ? '∞' : (string) (int) $days;
                    }),

                TextColumn::make('suggested_qty')
                    ->label('Suggested Reorder Qty')
                    ->state(fn (Stock $record): string => (string) static::getSuggestedQty($record)),

                TextColumn::make('preferred_supplier')
                    ->label('Preferred Supplier')
                    ->state(fn (Stock $record): string => static::getPreferredSupplier($record)->name ?? '—'),

                TextColumn::make('supplier_cost')
                    ->label('Supplier Unit Cost')
                    ->state(fn (Stock $record): string => number_format(static::getUnitCost($record), 3)),

                TextColumn::make('est_cost')
                    ->label('Est. Reorder Cost')
                    ->state(fn (Stock $record): string => number_format(static::getSuggestedQty($record) * static::getUnitCost($record), 2)),
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

                SelectFilter::make('urgency')
                    ->label('Urgency')
                    ->options([
                        'critical' => 'Critical (<7d)',
                        'warning' => 'Warning (<14d)',
                        'all' => 'All low stock',
                    ])
                    ->default('all')
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'critical' => static::whereDaysOfStockBelow($query, 7),
                        'warning' => static::whereDaysOfStockBelow($query, 14),
                        default => $query,
                    }),
            ])
            ->recordActions([
                Action::make('create_purchase_order')
                    ->label('Create PO')
                    ->icon(Heroicon::OutlinedShoppingCart)
                    ->url(fn (Stock $record): string => CreatePurchaseOrder::getUrl([
                        'product_id' => $record->stockable_id,
                        'supplier_id' => static::getPreferredSupplier($record)?->id,
                    ])),
            ])
            ->defaultSort('quantity', 'asc');
    }
}
