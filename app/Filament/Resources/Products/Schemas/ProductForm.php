<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Enums\ProductType;
use App\Models\Unit;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->required(),
                Select::make('type')
                    ->options(ProductType::class)
                    ->default(ProductType::Inventory)
                    ->required(),
                Select::make('categories')
                    ->multiple()
                    ->relationship('categories', 'name')
                    ->searchable()
                    ->preload(),
                Select::make('brand_id')
                    ->relationship('brand', 'name')
                    ->searchable()
                    ->nullable(),
                Grid::make(2)->schema([
                    Select::make('unit_id')
                        ->label('Unit of Measure')
                        ->relationship('unit', 'name')
                        ->searchable()
                        ->preload()
                        ->nullable()
                        ->live()
                        ->afterStateUpdated(fn () => null),
                    Placeholder::make('unit_conversion_info')
                        ->label('Conversion')
                        ->content(fn (Get $get): string => self::unitConversionInfo($get('unit_id')))
                        ->visible(fn (Get $get): bool => filled($get('unit_id'))),
                ]),
                Select::make('suppliers')
                    ->multiple()
                    ->relationship('suppliers', 'name')
                    ->searchable(),
                Select::make('addons')
                    ->multiple()
                    ->relationship('addons', 'name')
                    ->searchable()
                    ->preload(),
                TextInput::make('price')->numeric()->required(),
                TextInput::make('cost')->numeric()->required(),
                Textarea::make('description'),
                FileUpload::make('image')->image()->directory('products'),
            ]);
    }

    private static function unitConversionInfo(?int $unitId): string
    {
        if (! $unitId) {
            return '—';
        }

        $unit = Unit::with('baseUnit')->find($unitId);

        if (! $unit) {
            return '—';
        }

        if (! $unit->base_unit_id) {
            return "Base unit ({$unit->abbreviation})";
        }

        return "1 {$unit->abbreviation} = {$unit->conversion_factor} {$unit->baseUnit?->abbreviation}";
    }
}
