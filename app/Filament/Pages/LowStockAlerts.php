<?php

namespace App\Filament\Pages;

use Aldeebhasan\Inventorix\Facades\Inventorix;
use Aldeebhasan\Inventorix\Models\Stock;
use Aldeebhasan\Inventorix\Models\Threshold;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\PurchaseOrders\Pages\CreatePurchaseOrder;
use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
use BackedEnum;
use Filament\Actions\Action;
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

class LowStockAlerts extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.low-stock-alerts';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static string|UnitEnum|null $navigationGroup = 'Inventory Reports';

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
        return (string) Inventorix::lowStockItems(stockableType: Product::class)->count() ?: null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'danger';
    }

    public function table(Table $table): Table
    {
        $lowStockIds = Inventorix::lowStockItems(stockableType: Product::class)
            ->pluck('id');

        return $table
            ->query(
                Stock::query()
                    ->with(['stockable.brand', 'stockable.categories', 'location'])
                    ->whereIn('id', $lowStockIds)
            )
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
                    ->sortable(),

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
                    ->state(function (Stock $record): string {
                        $threshold = Threshold::query()
                            ->where('stockable_type', Product::class)
                            ->where('stockable_id', $record->stockable_id)
                            ->where(function ($q) use ($record) {
                                $q->where('location_id', $record->location_id)
                                    ->orWhereNull('location_id');
                            })
                            ->first();

                        return $threshold?->min_quantity !== null ? number_format((float) $threshold->min_quantity, 2) : '—';
                    }),

                TextColumn::make('deficit')
                    ->label('Deficit')
                    ->badge()
                    ->state(function (Stock $record): string {
                        $threshold = Threshold::query()
                            ->where('stockable_type', Product::class)
                            ->where('stockable_id', $record->stockable_id)
                            ->where(function ($q) use ($record) {
                                $q->where('location_id', $record->location_id)
                                    ->orWhereNull('location_id');
                            })
                            ->first();

                        if (! $threshold || $threshold->min_quantity === null) {
                            return '—';
                        }

                        $available = $record->quantity - $record->reserved_quantity;
                        $deficit = (float) $threshold->min_quantity - $available;

                        return $deficit > 0 ? number_format($deficit, 2) : '0.00';
                    })
                    ->color(function (Stock $record): string {
                        $threshold = Threshold::query()
                            ->where('stockable_type', Product::class)
                            ->where('stockable_id', $record->stockable_id)
                            ->where(function ($q) use ($record) {
                                $q->where('location_id', $record->location_id)
                                    ->orWhereNull('location_id');
                            })
                            ->first();

                        if (! $threshold || $threshold->min_quantity === null) {
                            return 'gray';
                        }

                        $available = $record->quantity - $record->reserved_quantity;
                        $deficit = (float) $threshold->min_quantity - $available;

                        return $deficit > 0 ? 'danger' : 'success';
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
