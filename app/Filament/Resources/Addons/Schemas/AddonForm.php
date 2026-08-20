<?php

namespace App\Filament\Resources\Addons\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class AddonForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->required(),
                TextInput::make('price')->numeric()->required()->minValue(0),
                Textarea::make('description')->default(null),
                Toggle::make('is_active')->default(true),
            ]);
    }
}
