<?php

namespace App\Filament\Resources\TransferOrders\Schemas;

use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TransferOrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Order Details')
                    ->schema([
                        Grid::make(3)->schema([
                            TextEntry::make('order_number')
                                ->columnSpan(1),
                            TextEntry::make('status')
                                ->badge()
                                ->color(fn ($state) => $state?->color())
                                ->columnSpan(1),
                            TextEntry::make('created_at')
                                ->dateTime()
                                ->columnSpan(1),
                            TextEntry::make('fromLocation.name')
                                ->label('From Location')
                                ->columnSpan(1),
                            TextEntry::make('toLocation.name')
                                ->label('To Location')
                                ->columnSpan(1),
                            TextEntry::make('createdBy.name')
                                ->label('Created By')
                                ->placeholder('—')
                                ->columnSpan(1),
                            TextEntry::make('notes')
                                ->columnSpanFull()
                                ->placeholder('—'),
                        ]),
                    ])->columnSpanFull(),
                Section::make('Lines')
                    ->schema([
                        RepeatableEntry::make('items')
                            ->schema([
                                Grid::make(5)->schema([
                                    TextEntry::make('product.name')
                                        ->label('Product')
                                        ->columnSpan(2),
                                    TextEntry::make('quantity')
                                        ->columnSpan(1),
                                    TextEntry::make('item_status')
                                        ->label('Status')
                                        ->badge()
                                        ->color(fn ($state) => $state?->color())
                                        ->columnSpan(1),
                                    TextEntry::make('failure_reason')
                                        ->label('Failure Reason')
                                        ->placeholder('—')
                                        ->columnSpan(1)
                                        ->visible(fn ($state) => filled($state)),
                                ]),
                            ])
                            ->columnSpanFull(),
                    ])->columnSpanFull(),
            ]);
    }
}
