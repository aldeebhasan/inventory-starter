<?php

namespace App\Filament\Resources\StockAdjustments\Schemas;

use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class StockAdjustmentInfolist
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
                            TextEntry::make('location.name')
                                ->label('Warehouse')
                                ->columnSpan(1),
                            TextEntry::make('reason')
                                ->columnSpan(2),
                            TextEntry::make('created_at')
                                ->dateTime()
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
                                Grid::make(6)->schema([
                                    TextEntry::make('product.name')
                                        ->label('Product')
                                        ->columnSpan(2),
                                    TextEntry::make('operation')
                                        ->badge()
                                        ->color(fn ($state) => $state?->color())
                                        ->columnSpan(1),
                                    TextEntry::make('current_stock')
                                        ->label('Stock at Confirm')
                                        ->placeholder('—')
                                        ->columnSpan(1),
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
                                        ->columnSpan(3)
                                        ->visible(fn ($state) => filled($state)),
                                ]),
                            ])
                            ->columnSpanFull(),
                    ])->columnSpanFull(),
            ]);
    }
}
