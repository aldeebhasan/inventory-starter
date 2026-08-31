<?php

namespace App\Filament\Resources\TransferOrders\Tables;

use App\Enums\TransferOrderStatus;
use App\Models\TransferOrder;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class TransferOrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('order_number')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('fromLocation.name')
                    ->label('From'),
                TextColumn::make('toLocation.name')
                    ->label('To'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state): ?string => $state?->label())
                    ->color(fn ($state) => $state?->color()),
                TextColumn::make('items_count')
                    ->counts('items')
                    ->label('Lines'),
                TextColumn::make('created_at')
                    ->date()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(TransferOrderStatus::class),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->visible(fn (TransferOrder $record) => $record->status === TransferOrderStatus::Draft),
                DeleteAction::make()
                    ->visible(fn (TransferOrder $record) => $record->status === TransferOrderStatus::Draft),
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
