<?php

namespace App\Filament\Resources\Products\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->required(),
                Select::make('category_id')->relationship('category', 'name')->required(),
                TextInput::make('price')->numeric()->required(),
                TextInput::make('cost')->numeric()->required(),
                Textarea::make('description'),
                FileUpload::make('image')->image()->directory('products'),
            ]);
    }
}
