<?php

namespace App\Filament\Resources\SupplierReturns\Schemas;

use App\Enums\PurchaseOrderStatus;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Unit;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class SupplierReturnForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('order_number')
                    ->unique(ignoreRecord: true)
                    ->disabled()
                    ->dehydrated(),
                Select::make('supplier_id')
                    ->relationship('supplier', 'name')
                    ->searchable()
                    ->required(),
                Select::make('purchase_order_id')
                    ->label('Original Purchase Order')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search, Get $get) => PurchaseOrder::query()
                        ->where('status', PurchaseOrderStatus::Received)
                        ->when($get('supplier_id'), fn ($query, $supplierId) => $query->where('supplier_id', $supplierId))
                        ->where('order_number', 'like', "%{$search}%")
                        ->limit(50)
                        ->pluck('order_number', 'id'))
                    ->getOptionLabelUsing(fn ($value) => PurchaseOrder::query()->whereKey($value)->value('order_number'))
                    ->nullable()
                    ->live()
                    ->afterStateUpdated(function (Set $set, $state) {
                        $set('items', []);

                        if ($state) {
                            $purchaseOrder = PurchaseOrder::query()->find($state);
                            if ($purchaseOrder) {
                                $set('supplier_id', $purchaseOrder->supplier_id);
                                $set('location_id', $purchaseOrder->location_id);
                            }
                        }
                    }),
                Select::make('location_id')
                    ->label('Warehouse')
                    ->options(fn () => Location::all()->pluck('name', 'id'))
                    ->searchable()
                    ->required(),
                TextInput::make('reason')
                    ->required(),
                Textarea::make('notes')
                    ->nullable(),
                Repeater::make('items')
                    ->relationship()
                    ->defaultItems(1)
                    ->minItems(1)
                    ->schema([
                        Grid::make(5)->schema([
                            Select::make('product_id')
                                ->label('Product')
                                // A linked purchase order has a bounded set of lines, so list them upfront.
                                ->options(fn (Get $get) => ($purchaseOrderId = (int) $get('../../purchase_order_id'))
                                    ? PurchaseOrderItem::query()
                                        ->where('purchase_order_id', $purchaseOrderId)
                                        ->with('product')
                                        ->get()
                                        ->pluck('product.name', 'product_id')
                                    : [])
                                ->searchable()
                                ->getSearchResultsUsing(function (string $search, Get $get) {
                                    $purchaseOrderId = (int) $get('../../purchase_order_id');

                                    if ($purchaseOrderId) {
                                        return PurchaseOrderItem::query()
                                            ->where('purchase_order_id', $purchaseOrderId)
                                            ->whereHas('product', fn ($query) => $query->where('name', 'like', "%{$search}%"))
                                            ->with('product')
                                            ->get()
                                            ->pluck('product.name', 'product_id');
                                    }

                                    return Product::query()
                                        ->where('name', 'like', "%{$search}%")
                                        ->limit(50)
                                        ->pluck('name', 'id');
                                })
                                ->getOptionLabelUsing(fn ($value) => Product::query()->whereKey($value)->value('name'))
                                ->required()
                                ->live()
                                ->columnSpan(2)
                                ->afterStateUpdated(function (Set $set, Get $get, $state) {
                                    $purchaseItem = self::purchaseOrderItem($get, (int) $state);

                                    if ($purchaseItem) {
                                        $set('price', $purchaseItem->unit_cost);
                                        $set('unit_id', $purchaseItem->unit_id);
                                        $set('quantity', null);

                                        return;
                                    }

                                    $set('price', Product::query()->whereKey((int) $state)->value('cost'));
                                    $set('unit_id', null);
                                }),
                            TextInput::make('quantity')
                                ->numeric()
                                ->required()
                                ->minValue(0.001)
                                ->maxValue(fn (Get $get) => self::purchaseOrderItem($get, (int) $get('product_id'))?->quantity)
                                ->columnSpan(1),
                            Select::make('unit_id')
                                ->label('Unit')
                                ->options(function (Get $get) {
                                    $productId = (int) $get('product_id');
                                    $purchaseItem = self::purchaseOrderItem($get, $productId);

                                    if ($purchaseItem) {
                                        return $purchaseItem->unit_id
                                            ? Unit::query()->whereKey($purchaseItem->unit_id)->pluck('name', 'id')->toArray()
                                            : [];
                                    }

                                    $unitId = Product::query()->whereKey($productId)->value('unit_id');

                                    if (! $unitId) {
                                        return Unit::query()->whereNull('base_unit_id')->pluck('name', 'id')->toArray();
                                    }

                                    return Unit::query()
                                        ->where('id', $unitId)
                                        ->orWhere('base_unit_id', $unitId)
                                        ->pluck('name', 'id')
                                        ->toArray();
                                })
                                ->disabled(fn (Get $get): bool => (bool) $get('../../purchase_order_id'))
                                ->dehydrated()
                                ->columnSpan(1),
                            TextInput::make('price')
                                ->label('Unit Cost')
                                ->numeric()
                                ->nullable()
                                ->columnSpan(1),
                        ]),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    /**
     * The original purchase order line for a product, when the return is linked to a purchase order.
     */
    protected static function purchaseOrderItem(Get $get, int $productId): ?PurchaseOrderItem
    {
        $purchaseOrderId = (int) $get('../../purchase_order_id');

        if (! $purchaseOrderId || ! $productId) {
            return null;
        }

        return PurchaseOrderItem::query()
            ->where('purchase_order_id', $purchaseOrderId)
            ->where('product_id', $productId)
            ->first();
    }
}
