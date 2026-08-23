<?php

namespace App\Filament\Pages;

use Aldeebhasan\Inventorix\Models\Stock;
use Aldeebhasan\Inventorix\Models\Threshold;
use App\Models\Location;
use App\Models\Product;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class Inventories extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.inventories';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static string|UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?string $navigationLabel = 'Stocks';

    public function getTitle(): string|Htmlable
    {
        return 'Stocks';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Stock::query()
                    ->with(['stockable.brand', 'stockable.categories', 'location'])
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

                TextColumn::make('stockable.brand.name')
                    ->label('Brand')
                    ->placeholder('—'),

                TextColumn::make('location.name')
                    ->label('Location')
                    ->sortable(),

                TextColumn::make('quantity')
                    ->label('On Hand')
                    ->numeric(decimalPlaces: 2)
                    ->sortable(),

                TextColumn::make('reserved_quantity')
                    ->label('Reserved')
                    ->numeric(decimalPlaces: 2)
                    ->sortable(),

                TextColumn::make('available_quantity')
                    ->label('Available')
                    ->numeric(decimalPlaces: 2)
                    ->state(fn (Stock $record): float => $record->quantity - $record->reserved_quantity),

                TextColumn::make('threshold_min')
                    ->label('Min Threshold')
                    ->state(function (Stock $record): string {
                        $threshold = Threshold::query()->where('stockable_type', Product::class)
                            ->where('stockable_id', $record->stockable_id)
                            ->where('location_id', $record->location_id)
                            ->first();

                        return $threshold?->min_quantity !== null ? number_format((float) $threshold->min_quantity, 2) : '—';
                    }),

                TextColumn::make('threshold_max')
                    ->label('Max Threshold')
                    ->state(function (Stock $record): string {
                        $threshold = Threshold::query()->where('stockable_type', Product::class)
                            ->where('stockable_id', $record->stockable_id)
                            ->where('location_id', $record->location_id)
                            ->first();

                        return $threshold?->max_quantity !== null ? number_format((float) $threshold->max_quantity, 2) : '—';
                    }),

                TextColumn::make('stock_status')
                    ->label('Status')
                    ->badge()
                    ->state(function (Stock $record): string {
                        $threshold = Threshold::query()->where('stockable_type', Product::class)
                            ->where('stockable_id', $record->stockable_id)
                            ->where('location_id', $record->location_id)
                            ->first();

                        if ($threshold === null) {
                            return 'No Threshold';
                        }

                        $available = $record->quantity - $record->reserved_quantity;

                        return $available <= $threshold->min_quantity ? 'Low Stock' : 'OK';
                    })
                    ->color(function (Stock $record): string {
                        $threshold = Threshold::query()->where('stockable_type', Product::class)
                            ->where('stockable_id', $record->stockable_id)
                            ->where('location_id', $record->location_id)
                            ->first();

                        if ($threshold === null) {
                            return 'gray';
                        }

                        $available = $record->quantity - $record->reserved_quantity;

                        return $available <= $threshold->min_quantity ? 'danger' : 'success';
                    }),
            ])
            ->filters([
                SelectFilter::make('location_id')
                    ->label('Location')
                    ->options(fn () => Location::query()->where('is_active', true)->pluck('name', 'id'))
                    ->searchable(),

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
            ])
            ->recordActions([
                Action::make('set_threshold')
                    ->label('Set Threshold')
                    ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
                    ->fillForm(function (Stock $record): array {
                        $threshold = Threshold::query()->where('stockable_type', Product::class)
                            ->where('stockable_id', $record->stockable_id)
                            ->where('location_id', $record->location_id)
                            ->first();

                        return [
                            'min_quantity' => $threshold?->min_quantity,
                            'max_quantity' => $threshold?->max_quantity,
                        ];
                    })
                    ->schema([
                        TextInput::make('min_quantity')
                            ->label('Min Quantity')
                            ->numeric()
                            ->minValue(0)
                            ->required(),

                        TextInput::make('max_quantity')
                            ->label('Max Quantity')
                            ->numeric()
                            ->minValue(0)
                            ->nullable(),
                    ])
                    ->action(function (Stock $record, array $data): void {
                        $product = $record->stockable()->first();

                        if ($product instanceof Product) {
                            $product->setStockThreshold(
                                min: (float) $data['min_quantity'],
                                max: filled($data['max_quantity'] ?? null) ? (float) $data['max_quantity'] : null,
                                location: $record->location_id,
                            );
                        }
                    })
                    ->modalHeading(function (Stock $record): string {
                        $product = $record->stockable()->first();

                        return 'Set Threshold — '.($product instanceof Product ? $product->name : '');
                    })
                    ->modalSubmitActionLabel('Save Threshold'),
            ])
            ->defaultSort('stockable_id');
    }
}
