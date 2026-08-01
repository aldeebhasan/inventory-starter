<?php

namespace App\Filament\Resources\Locations\Schemas;

use App\Enums\LocationType;
use App\Models\Location;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class LocationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->required(),
                TextInput::make('code')
                    ->unique(table: Location::class, column: 'code', ignoreRecord: true)
                    ->default(null),
                Select::make('meta.type')
                    ->options(collect(LocationType::cases())->mapWithKeys(
                        fn (LocationType $type) => [$type->value => $type->label()]
                    ))
                    ->required()
                    ->label('Type'),
                Select::make('parent_id')
                    ->relationship('parent', 'name')
                    ->default(null)
                    ->nullable(),
                Toggle::make('is_active')->default(true),
            ]);
    }
}
