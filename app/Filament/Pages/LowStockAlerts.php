<?php

namespace App\Filament\Pages;

use Aldeebhasan\Inventorix\Models\Stock;
use Aldeebhasan\Inventorix\Models\Threshold;
use App\Filament\Exports\LowStockAlertsExporter;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\PurchaseOrders\Pages\CreatePurchaseOrder;
use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
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
use Illuminate\Database\Query\Builder as QueryBuilder;
use UnitEnum;

class LowStockAlerts extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.low-stock-alerts';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static string|UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?string $navigationParentItem = 'Reports';

    protected static ?int $navigationSort = 7;

    protected static ?string $navigationLabel = 'Low Stock Alerts';

    public function getTitle(): string|Htmlable
    {
        return 'Low Stock Alerts';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Products that have fallen below their minimum threshold. Create purchase orders directly from here.';
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) static::lowStockQuery()->count() ?: null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'danger';
    }

    /**
     * Product stocks at or below a min threshold (location-specific or global), as SQL — nothing is preloaded into PHP.
     *
     * @return Builder<Stock>
     */
    public static function lowStockQuery(): Builder
    {
        return Stock::query()
            ->where('inventorix_stocks.stockable_type', (new Product)->getMorphClass())
            ->whereExists(function (QueryBuilder $sub): void {
                $sub->from('inventorix_thresholds')
                    ->whereColumn('inventorix_thresholds.stockable_type', 'inventorix_stocks.stockable_type')
                    ->whereColumn('inventorix_thresholds.stockable_id', 'inventorix_stocks.stockable_id')
                    ->where(function (QueryBuilder $q): void {
                        $q->whereColumn('inventorix_thresholds.location_id', 'inventorix_stocks.location_id')
                            ->orWhereNull('inventorix_thresholds.location_id');
                    })
                    ->whereColumn('inventorix_stocks.quantity', '<=', 'inventorix_thresholds.min_quantity');
            });
    }

    /**
     * Min quantity of the stock's threshold, preferring the location-specific one over the global one.
     */
    protected static function minThresholdSubquery(): QueryBuilder
    {
        return Threshold::query()
            ->select('min_quantity')
            ->whereColumn('inventorix_thresholds.stockable_type', 'inventorix_stocks.stockable_type')
            ->whereColumn('inventorix_thresholds.stockable_id', 'inventorix_stocks.stockable_id')
            ->where(function (Builder $q): void {
                $q->whereColumn('inventorix_thresholds.location_id', 'inventorix_stocks.location_id')
                    ->orWhereNull('inventorix_thresholds.location_id');
            })
            ->orderByRaw('inventorix_thresholds.location_id is null')
            ->limit(1)
            ->toBase();
    }

    public static function deficit(Stock $record): ?float
    {
        $min = $record->getAttribute('min_threshold_quantity');

        if ($min === null) {
            return null;
        }

        return max(0.0, (float) $min - ($record->quantity - $record->reserved_quantity));
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                static::lowStockQuery()
                    ->select('inventorix_stocks.*')
                    ->addSelect(['min_threshold_quantity' => static::minThresholdSubquery()])
                    ->with(['stockable' => fn (MorphTo $morphTo) => $morphTo->morphWith([Product::class => ['brand']]), 'location'])
            )
            ->headerActions([
                ExportAction::make()->exporter(LowStockAlertsExporter::class),
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
                    ->url(fn (Stock $record) => $record->stockable ? ProductResource::getUrl('view', ['record' => $record->stockable_id]) : null)
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy(
                        Product::query()->select('name')->whereColumn('products.id', 'inventorix_stocks.stockable_id'),
                        $direction,
                    )),

                TextColumn::make('stockable.brand.name')
                    ->label('Brand')
                    ->placeholder('—'),

                TextColumn::make('location.name')
                    ->label('Location')
                    ->sortable(),

                TextColumn::make('quantity')
                    ->label('On Hand')
                    ->numeric(decimalPlaces: 2)
                    ->sortable(),

                TextColumn::make('reserved_quantity')
                    ->label('Reserved')
                    ->numeric(decimalPlaces: 2)
                    ->sortable(),

                TextColumn::make('available')
                    ->label('Available')
                    ->numeric(decimalPlaces: 2)
                    ->state(fn (Stock $record): float => $record->quantity - $record->reserved_quantity),

                TextColumn::make('min_threshold')
                    ->label('Min Threshold')
                    ->state(fn (Stock $record): string => Inventories::formatThreshold($record->getAttribute('min_threshold_quantity'))),

                TextColumn::make('deficit')
                    ->label('Deficit')
                    ->badge()
                    ->state(function (Stock $record): string {
                        $deficit = static::deficit($record);

                        return $deficit === null ? '—' : number_format($deficit, 2);
                    })
                    ->color(fn (Stock $record): string => match (true) {
                        static::deficit($record) === null => 'gray',
                        static::deficit($record) > 0 => 'danger',
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
            ])
            ->recordActions([
                Action::make('create_purchase_order')
                    ->label('Create PO')
                    ->icon(Heroicon::OutlinedShoppingCart)
                    ->url(fn (Stock $record): string => CreatePurchaseOrder::getUrl([
                        'product_id' => $record->stockable_id,
                    ])),
            ])
            ->defaultSort('quantity', 'asc');
    }
}
