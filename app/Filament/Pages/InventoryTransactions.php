<?php

namespace App\Filament\Pages;

use Aldeebhasan\Inventorix\Enums\MovementType;
use Aldeebhasan\Inventorix\Models\Movement;
use App\Models\Location;
use App\Models\Product;
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

class InventoryTransactions extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.inventory-transactions';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsUpDown;

    protected static string|UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?string $navigationLabel = 'Stock Movements';

    public function getTitle(): string|Htmlable
    {
        return 'Stock Movements';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Movement::query()
                    ->with(['stockable', 'location', 'transaction'])
                    ->whereHasMorph('stockable', [Product::class])
            )
            ->columns([
                TextColumn::make('stockable.name')
                    ->label('Product')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHasMorph(
                            'stockable',
                            [Product::class],
                            fn (Builder $q) => $q->where('name', 'like', "%{$search}%")
                        );
                    })
                    ->sortable(),

                TextColumn::make('location.name')
                    ->label('Location')
                    ->sortable(),

                TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->color(fn (MovementType $state): string => match ($state) {
                        MovementType::Add => 'success',
                        MovementType::Deduct => 'danger',
                    }),

                TextColumn::make('quantity')
                    ->label('Qty')
                    ->numeric(decimalPlaces: 2)
                    ->sortable(),

                TextColumn::make('before_after')
                    ->label('Before → After')
                    ->state(fn (Movement $record): string => number_format((float) $record->before_quantity, 2).' → '.number_format((float) $record->after_quantity, 2)),

                TextColumn::make('cost_per_unit')
                    ->label('Cost / Unit')
                    ->numeric(decimalPlaces: 2)
                    ->placeholder('—'),

                TextColumn::make('transaction.type')
                    ->label('Transaction Type')
                    ->badge()
                    ->placeholder('—'),

                TextColumn::make('note')
                    ->label('Note')
                    ->placeholder('—')
                    ->limit(40)
                    ->tooltip(fn (Movement $record): ?string => $record->note),

                TextColumn::make('lot_reference')
                    ->label('Lot Ref')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('external_reference')
                    ->label('External Ref')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('stockable_id')
                    ->label('Product')
                    ->options(fn () => Product::query()->pluck('name', 'id'))
                    ->searchable()
                    ->query(function (Builder $query, array $data): Builder {
                        if (blank($data['value'])) {
                            return $query;
                        }

                        return $query->where('stockable_id', $data['value'])
                            ->where('stockable_type', Product::class);
                    }),

                SelectFilter::make('location_id')
                    ->label('Location')
                    ->options(fn () => Location::query()->active()->pluck('name', 'id'))
                    ->searchable(),

                SelectFilter::make('type')
                    ->label('Movement Type')
                    ->options([
                        MovementType::Add->value => 'Add',
                        MovementType::Deduct->value => 'Deduct',
                    ]),

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
            ])
            ->defaultSort('created_at', 'desc');
    }
}
