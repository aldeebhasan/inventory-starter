<?php

namespace App\Filament\Resources\ReportDefinitions\Tables;

use App\Filament\Resources\ReportDefinitions\ReportDefinitionResource;
use App\Models\ReportDefinition;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class ReportDefinitionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('user:id,name'))
            ->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('name')->sortable()->searchable(),
                TextColumn::make('definition.base')
                    ->label('Base dataset')
                    ->formatStateUsing(fn (string $state): string => Str::headline($state)),
                TextColumn::make('definition.visualization')
                    ->label('Shown as')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => Str::headline($state ?? 'table')),
                TextColumn::make('user.name')->label('Owner'),
                IconColumn::make('is_shared')->label('Shared')->boolean(),
                TextColumn::make('updated_at')->dateTime()->sortable(),
            ])
            ->filters([
                TernaryFilter::make('mine')
                    ->label('Owner')
                    ->trueLabel('Mine')
                    ->falseLabel('Shared with me')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->where('user_id', auth()->id()),
                        false: fn (Builder $query): Builder => $query->where('user_id', '!=', auth()->id()),
                    ),
                TrashedFilter::make(),
            ])
            ->recordUrl(fn (ReportDefinition $record): string => ReportDefinitionResource::getUrl('view', ['record' => $record]))
            ->recordActions([
                ViewAction::make()->label('Open'),
                DeleteAction::make(),
                RestoreAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ])
            ->checkIfRecordIsSelectableUsing(fn (ReportDefinition $record): bool => $record->isOwnedBy(auth()->user()));
    }
}
