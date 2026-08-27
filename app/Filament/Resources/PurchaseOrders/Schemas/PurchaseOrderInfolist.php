<?php

namespace App\Filament\Resources\PurchaseOrders\Schemas;

use App\Filament\Schemas\StatusHistorySection;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PurchaseOrderInfolist
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
                            TextEntry::make('ordered_at')
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
                                Grid::make(5)->schema([
                                    TextEntry::make('product.name')
                                        ->label('Product')
                                        ->columnSpan(2),
                                    TextEntry::make('unit.name')
                                        ->label('Unit')
                                        ->placeholder('-')
                                        ->columnSpan(1),
                                    TextEntry::make('quantity')
                                        ->columnSpan(1),
                                    TextEntry::make('unit_cost')
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
