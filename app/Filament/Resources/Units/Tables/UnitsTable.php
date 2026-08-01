<?php

namespace App\Filament\Resources\Units\Tables;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UnitsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->sortable()->searchable(),
                TextColumn::make('abbreviation'),
                TextColumn::make('baseUnit.name')
                    ->label('Base Unit')
                    ->placeholder('—'),
                TextColumn::make('conversion_factor')
                    ->label('Factor')
                    ->numeric(decimalPlaces: 6)
                    ->placeholder('—')
                    ->tooltip(fn ($record): string => $record->base_unit_id
                        ? "1 {$record->abbreviation} = {$record->conversion_factor} {$record->baseUnit?->abbreviation}"
                        : 'Base unit'
                    ),
                TextColumn::make('products_count')
                    ->counts('products')
                    ->label('Products'),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
