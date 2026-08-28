<?php

namespace App\Filament\Resources\RefundedOrders\Tables;

use App\Enums\ReturnOrderStatus;
use App\Models\ReturnOrder;
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

class RefundedOrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('order_number')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('customer.name')
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn ($state) => $state?->color()),
                TextColumn::make('created_at')
                    ->date()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(ReturnOrderStatus::class),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->visible(fn (ReturnOrder $record) => $record->status === ReturnOrderStatus::Draft),
                DeleteAction::make()
                    ->visible(fn (ReturnOrder $record) => $record->status === ReturnOrderStatus::Draft),
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
