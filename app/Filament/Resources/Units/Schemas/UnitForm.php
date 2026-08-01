<?php

namespace App\Filament\Resources\Units\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class UnitForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Unit Details')
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('name')
                                ->required()
                                ->placeholder('e.g. Kilogram'),
                            TextInput::make('abbreviation')
                                ->required()
                                ->maxLength(20)
                                ->placeholder('e.g. kg'),
                        ]),
                    ]),

                Section::make('Conversion')
                    ->description('Define this unit relative to a base unit. Leave blank if this is a base unit itself.')
                    ->schema([
                        Select::make('base_unit_id')
                            ->label('Base Unit')
                            ->relationship('baseUnit', 'name')
                            ->searchable()
                            ->preload()
                            ->nullable()
                            ->live()
                            ->placeholder('None — this is a base unit'),

                        Grid::make(2)
                            ->schema([
                                TextInput::make('conversion_factor')
                                    ->label('Conversion Factor')
                                    ->numeric()
                                    ->minValue(0.000001)
                                    ->default(1)
                                    ->required()
                                    ->helperText(fn (Get $get): string => self::conversionHint($get)),
                            ])
                            ->visible(fn (Get $get): bool => filled($get('base_unit_id'))),
                    ]),
            ]);
    }

    private static function conversionHint(Get $get): string
    {
        $factor = $get('conversion_factor');
        $baseId = $get('base_unit_id');

        if (! $factor || ! $baseId) {
            return 'How many base units equal 1 of this unit.';
        }

        return "1 of this unit = {$factor} of the base unit.";
    }
}
