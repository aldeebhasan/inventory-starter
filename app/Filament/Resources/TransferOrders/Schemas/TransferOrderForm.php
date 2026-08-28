<?php

namespace App\Filament\Resources\TransferOrders\Schemas;

use App\Enums\ProductType;
use App\Models\Location;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;

class TransferOrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('order_number')
                    ->unique(ignoreRecord: true)
                    ->disabled()
                    ->dehydrated(),
                Select::make('from_location_id')
                    ->label('From Location')
                    ->options(fn () => Location::all()->pluck('name', 'id'))
                    ->searchable()
                    ->required(),
                Select::make('to_location_id')
                    ->label('To Location')
                    ->options(fn () => Location::all()->pluck('name', 'id'))
                    ->searchable()
                    ->required()
                    ->different('from_location_id'),
                Textarea::make('notes')
                    ->nullable(),
                Repeater::make('items')
                    ->relationship()
                    ->defaultItems(1)
                    ->minItems(1)
                    ->schema([
                        Grid::make(3)->schema([
                            Select::make('product_id')
                                ->relationship('product', 'name', fn ($query) => $query->where('type', ProductType::Inventory))
                                ->searchable()
                                ->required()
                                ->columnSpan(2),
                            TextInput::make('quantity')
                                ->numeric()
                                ->required()
                                ->minValue(0.001)
                                ->columnSpan(1),
                        ]),
                    ])
                    ->columnSpanFull(),
            ]);
    }
}
