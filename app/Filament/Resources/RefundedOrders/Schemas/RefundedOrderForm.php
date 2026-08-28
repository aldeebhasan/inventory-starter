<?php

namespace App\Filament\Resources\RefundedOrders\Schemas;

use App\Models\Location;
use App\Models\Product;
use App\Models\SaleOrder;
use App\Models\SaleOrderItem;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class RefundedOrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('order_number')
                    ->unique(ignoreRecord: true)
                    ->disabled()
                    ->dehydrated(),
                Select::make('customer_id')
                    ->relationship('customer', 'name')
                    ->searchable()
                    ->required(),
                Select::make('original_order_id')
                    ->label('Original Sale Order')
                    ->options(fn () => SaleOrder::query()->pluck('order_number', 'id'))
                    ->searchable()
                    ->nullable()
                    ->live()
                    ->afterStateUpdated(fn (Set $set) => $set('items', [])),
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
                        Grid::make(4)->schema([
                            Select::make('product_id')
                                ->label('Product')
                                ->options(function (Get $get) {
                                    $originalOrderId = (int) $get('../../original_order_id');

                                    if ($originalOrderId) {
                                        return SaleOrderItem::query()
                                            ->where('sale_order_id', $originalOrderId)
                                            ->with('product')
                                            ->get()
                                            ->pluck('product.name', 'product_id');
                                    }

                                    return Product::query()->pluck('name', 'id');
                                })
                                ->searchable()
                                ->required()
                                ->live()
                                ->columnSpan(2)
                                ->afterStateUpdated(function (Set $set, Get $get, $state) {
                                    $productId = (int) $state;
                                    $originalOrderId = (int) $get('../../original_order_id');

                                    if ($originalOrderId && $productId) {
                                        $saleItem = SaleOrderItem::query()
                                            ->where('sale_order_id', $originalOrderId)
                                            ->where('product_id', $productId)
                                            ->first();

                                        if ($saleItem) {
                                            $set('price', $saleItem->unit_price);

                                            return;
                                        }
                                    }

                                    $product = Product::query()->find($productId);
                                    $set('price', $product?->price);
                                }),
                            TextInput::make('quantity')
                                ->numeric()
                                ->required()
                                ->columnSpan(1),
                            TextInput::make('price')
                                ->numeric()
                                ->nullable()
                                ->columnSpan(1),
                        ]),
                    ])
                    ->columnSpanFull(),
            ]);
    }
}
