<?php

namespace App\Filament\Pages;

use Aldeebhasan\Inventorix\Enums\TransactionStatus;
use Aldeebhasan\Inventorix\Enums\TransactionType;
use Aldeebhasan\Inventorix\Models\Transaction;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class TransactionLedger extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.transaction-ledger';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?int $navigationSort = 12;

    protected static ?string $navigationLabel = 'Transaction Ledger';

    public function getTitle(): string|Htmlable
    {
        return 'Transaction Ledger';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Transaction::query()
                    ->with('causable')
                    ->withCount('movements')
                    ->withSum('movements', 'quantity')
            )
            ->columns([
                TextColumn::make('uuid')
                    ->label('UUID')
                    ->formatStateUsing(fn (string $state): string => substr($state, 0, 8))
                    ->tooltip(fn (Transaction $record): string => $record->uuid)
                    ->copyable()
                    ->searchable(),

                TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->color(fn (TransactionType $state): string => match ($state) {
                        TransactionType::Purchase => 'primary',
                        TransactionType::Sale => 'success',
                        TransactionType::Transfer => 'info',
                        TransactionType::Adjustment => 'warning',
                        TransactionType::Manual => 'gray',
                        TransactionType::Reversal => 'danger',
                    }),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (TransactionStatus $state): string => match ($state) {
                        TransactionStatus::Committed => 'success',
                        TransactionStatus::Pending => 'warning',
                        TransactionStatus::RolledBack => 'danger',
                    }),

                TextColumn::make('source_document')
                    ->label('Source Document')
                    ->state(function (Transaction $record): string {
                        $causable = $record->causable;
                        if (! $causable) {
                            return '—';
                        }

                        return $causable->order_number ?? class_basename($causable).' #'.$causable->id;
                    }),

                TextColumn::make('movements_count')
                    ->label('Movements')
                    ->sortable(),

                TextColumn::make('movements_sum_quantity')
                    ->label('Total Qty')
                    ->numeric(decimalPlaces: 2)
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Created At')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Filter::make('date_range')
                    ->label('Date Range')
                    ->form([
                        DatePicker::make('from')->label('From'),
                        DatePicker::make('until')->label('Until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'], fn (Builder $q) => $q->whereDate('created_at', '>=', $data['from']))
                            ->when($data['until'], fn (Builder $q) => $q->whereDate('created_at', '<=', $data['until']));
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($data['from'] ?? null) {
                            $indicators[] = 'From: '.$data['from'];
                        }
                        if ($data['until'] ?? null) {
                            $indicators[] = 'Until: '.$data['until'];
                        }

                        return $indicators;
                    }),

                SelectFilter::make('type')
                    ->label('Transaction Type')
                    ->options(TransactionType::class),

                SelectFilter::make('status')
                    ->label('Status')
                    ->options(TransactionStatus::class),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
