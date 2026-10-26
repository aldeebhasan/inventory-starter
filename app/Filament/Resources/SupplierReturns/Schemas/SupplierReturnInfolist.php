<?php

namespace App\Filament\Resources\SupplierReturns\Schemas;

use App\Filament\Schemas\StatusHistorySection;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SupplierReturnInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Return Details')
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
                            TextEntry::make('supplier.name')
                                ->columnSpan(1),
                            TextEntry::make('location.name')
                                ->label('Warehouse')
                                ->columnSpan(1),
                            TextEntry::make('createdBy.name')
                                ->label('Created By')
                                ->placeholder('-')
                                ->columnSpan(1),
                            TextEntry::make('purchaseOrder.order_number')
                                ->label('Original Purchase Order')
                                ->placeholder('-')
                                ->columnSpan(1),
                            TextEntry::make('reason')
                                ->placeholder('-')
                                ->columnSpan(2),
                            TextEntry::make('notes')
                                ->columnSpanFull()
                                ->placeholder('-'),
                        ]),
                    ])->columnSpanFull(),
                Section::make('Lines')
                    ->schema([
                        RepeatableEntry::make('items')
                            ->label('')
                            ->schema([
                                Grid::make(4)->schema([
                                    TextEntry::make('product.name')
                                        ->label('Product')
                                        ->columnSpan(2),
                                    TextEntry::make('quantity')
                                        ->columnSpan(1),
                                    TextEntry::make('price')
                                        ->label('Unit Cost')
                                        ->placeholder('-')
                                        ->columnSpan(1),
                                ]),
                            ])
                            ->columnSpanFull(),
                    ])->columnSpanFull(),
                StatusHistorySection::make(),
            ]);
    }
}
