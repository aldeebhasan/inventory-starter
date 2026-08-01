<?php

namespace App\Filament\Resources\SaleOrders\Schemas;

use App\Models\Location;
use App\Models\Product;
use App\Models\Unit;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class SaleOrderForm
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
                Select::make('location_id')
                    ->label('Warehouse')
                    ->options(fn () => Location::all()->pluck('name', 'id'))
                    ->searchable()
                    ->required(),
                DateTimePicker::make('ordered_at')
                    ->default(now())
                    ->required(),
                Textarea::make('notes')
                    ->nullable(),
                Repeater::make('items')
                    ->relationship()
                    ->defaultItems(0)
                    ->schema([
                        Grid::make(5)->schema([
                            Select::make('product_id')
                                ->relationship('product', 'name')
                                ->searchable()
                                ->required()
                                ->live()
                                ->columnSpan(2)
                                ->afterStateUpdated(function (Set $set, $state) {
                                    $product = Product::query()->find((int) $state);
                                    $set('unit_id', $product?->unit_id);
                                }),
                            Select::make('unit_id')
                                ->label('Unit')
                                ->options(function (Get $get): array {
                                    $product = Product::query()->find((int) $get('product_id') ?: null);
                                    if (! $product?->unit_id) {
                                        return Unit::query()->whereNull('base_unit_id')->pluck('name', 'id')->toArray();
                                    }

                                    return Unit::query()->where('id', $product->unit_id)
                                        ->orWhere('base_unit_id', $product->unit_id)
                                        ->pluck('name', 'id')
                                        ->toArray();
                                })
                                ->nullable()
                                ->searchable()
                                ->columnSpan(1),
                            TextInput::make('quantity')
                                ->numeric()
                                ->required()
                                ->columnSpan(1),
                            TextInput::make('unit_price')
                                ->numeric()
                                ->nullable()
                                ->columnSpan(1),
                        ]),
                    ])
                    ->columnSpanFull(),
            ]);
    }
}
