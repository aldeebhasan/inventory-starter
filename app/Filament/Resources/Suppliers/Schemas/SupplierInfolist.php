<?php

namespace App\Filament\Resources\Suppliers\Schemas;

use App\Models\Unit;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SupplierInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Details')->schema([
                    TextEntry::make('name'),
                    TextEntry::make('email'),
                    TextEntry::make('phone')->default('—'),
                    TextEntry::make('address')->default('—'),
                    TextEntry::make('tax_number')->default('—'),
                    IconEntry::make('is_active')->boolean(),
                ])->columns(2),

                Section::make('Product Costs')->schema([
                    RepeatableEntry::make('products')
                        ->table([
                            TableColumn::make('Product'),
                            TableColumn::make('Unit Cost'),
                            TableColumn::make('Unit'),
                        ])
                        ->schema([
                            TextEntry::make('name'),
                            TextEntry::make('pivot.unit_cost')
                                ->numeric(decimalPlaces: 3)
                                ->default('—'),
                            TextEntry::make('pivot.unit_id')
                                ->label('Unit')
                                ->formatStateUsing(function ($state) {
                                    if (! $state) {
                                        return '—';
                                    }

                                    return Unit::find($state)?->abbreviation ?? '—';
                                }),
                        ]),
                ]),
            ]);
    }
}
