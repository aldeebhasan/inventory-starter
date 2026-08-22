<?php

namespace App\Filament\Resources\StockAdjustments\Schemas;

use App\Enums\StockAdjustmentOperation;
use App\Models\Location;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;

class StockAdjustmentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('order_number')
                    ->unique(ignoreRecord: true)
                    ->disabled()
                    ->dehydrated(),
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
                    ->defaultItems(0)
                    ->schema([
                        Grid::make(4)->schema([
                            Select::make('product_id')
                                ->relationship('product', 'name')
                                ->searchable()
                                ->required()
                                ->columnSpan(2),
                            Select::make('operation')
                                ->options(StockAdjustmentOperation::class)
                                ->required()
                                ->columnSpan(1),
                            TextInput::make('quantity')
                                ->numeric()
                                ->minValue(0.0001)
                                ->required()
                                ->columnSpan(1),
                        ]),
                    ])
                    ->columnSpanFull(),
            ]);
    }
}
