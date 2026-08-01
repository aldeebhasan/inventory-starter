<?php

namespace App\Filament\Resources\Locations\Tables;

use App\Enums\LocationType;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class LocationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->sortable()->searchable(),
                TextColumn::make('code')->sortable(),
                TextColumn::make('meta.type')->badge()->label('Type'),
                TextColumn::make('parent.name')->label('Parent'),
                IconColumn::make('is_active')->boolean(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->options(collect(LocationType::cases())->mapWithKeys(
                        fn (LocationType $type) => [$type->value => $type->label()]
                    ))
                    ->query(fn ($query, $data) => $data['value']
                        ? $query->where('meta->type', $data['value'])
                        : $query
                    ),
                TernaryFilter::make('is_active'),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
