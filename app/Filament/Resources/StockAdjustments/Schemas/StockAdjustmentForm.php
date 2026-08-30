<?php

namespace App\Filament\Resources\StockAdjustments\Schemas;

use App\Enums\ProductType;
use App\Enums\StockAdjustmentOperation;
use App\Models\Location;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
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
                    ->defaultItems(1)
                    ->minItems(1)
                    ->schema([
                        Grid::make(5)->schema([
                            Select::make('product_id')
                                ->relationship('product', 'name', fn ($query) => $query->where('type', ProductType::Inventory))
                                ->searchable()
                                ->required()
                                ->columnSpan(2),
                            Select::make('operation')
                                ->options(StockAdjustmentOperation::class)
                                ->required()
                                ->live()
                                ->columnSpan(1),
                            TextInput::make('quantity')
                                ->numeric()
                                ->minValue(0.0001)
                                ->required()
                                ->columnSpan(1),
                            TextInput::make('cost')
                                ->numeric()
                                ->minValue(0)
                                ->visible(fn (Get $get): bool => in_array($get('operation'), [
                                    StockAdjustmentOperation::Increase->value,
                                    StockAdjustmentOperation::Increase,
                                    StockAdjustmentOperation::Adjust->value,
                                    StockAdjustmentOperation::Adjust,
                                ]))
                                ->columnSpan(1),
                        ]),
                    ])
                    ->columnSpanFull(),
            ]);
    }
}
