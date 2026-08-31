<?php

namespace App\Filament\Resources\Products\Tables;

use App\Enums\ProductType;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                ImageColumn::make('image')->square()->defaultImageUrl(asset('images/default.png')),
                TextColumn::make('name')->sortable()->searchable(),
                TextColumn::make('type')
                    ->badge()
                    ->color(fn (ProductType $state): string => $state->color()),
                TextColumn::make('brand.name')->sortable(),
                TextColumn::make('categories_count')->counts('categories')->label('Categories'),
                TextColumn::make('price')->numeric()->sortable(),
                TextColumn::make('cost')->numeric()->sortable(),
            ])
            ->filters([
                SelectFilter::make('type')->options(ProductType::class),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
