<?php

namespace App\Filament\Schemas;

use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;

class StatusHistorySection
{
    public static function make(): Section
    {
        return Section::make('Status History')
            ->schema([
                RepeatableEntry::make('statusLogs')
                    ->label('')
                    ->schema([
                        Grid::make(5)->schema([
                            TextEntry::make('new_status')
                                ->label('Status')
                                ->badge()
                                ->columnSpan(1),
                            TextEntry::make('old_status')
                                ->label('From')
                                ->placeholder('(initial)')
                                ->columnSpan(1),
                            TextEntry::make('reason')
                                ->placeholder('-')
                                ->columnSpan(1),
                            TextEntry::make('creator.name')
                                ->label('Changed by')
                                ->placeholder('-')
                                ->columnSpan(1),
                            TextEntry::make('created_at')
                                ->label('Date')
                                ->dateTime()
                                ->columnSpan(1),
                        ]),
                    ])
                    ->columnSpanFull(),
            ])
            ->collapsible()
            ->collapsed()
            ->columnSpanFull();
    }
}
