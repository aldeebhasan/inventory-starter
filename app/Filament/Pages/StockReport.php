<?php

namespace App\Filament\Pages;

use Aldeebhasan\Inventorix\Models\Stock;
use Aldeebhasan\Inventorix\Models\Threshold;
use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class StockReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.stock-report';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?int $navigationSort = 8;

    protected static ?string $navigationLabel = 'Stock Report';

    public function getTitle(): string|Htmlable
    {
        return 'Stock Report';
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

                TextColumn::make('stockable.categories')
                    ->label('Categories')
                    ->state(function (Stock $record): string {
                        $product = $record->stockable;

                        return $product?->categories?->pluck('name')->join(', ') ?: '—';
                    }),

                TextColumn::make('location.name')
                    ->label('Location')
                    ->sortable(),

                TextColumn::make('quantity')
                    ->label('Qty On Hand')
                    ->numeric(decimalPlaces: 2)
                    ->sortable(),

                TextColumn::make('reserved_quantity')
                    ->label('Reserved')
                    ->numeric(decimalPlaces: 2)
                    ->sortable(),

                TextColumn::make('available')
                    ->label('Available')
                    ->numeric(decimalPlaces: 2)
                    ->state(fn (Stock $record): float => $record->quantity - $record->reserved_quantity)
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query->orderByRaw('(quantity - reserved_quantity) '.$direction);
                    }),

                TextColumn::make('valuation')
                    ->label('Valuation')
                    ->state(function (Stock $record): string {
                        $available = $record->quantity - $record->reserved_quantity;
                        $cost = $record->stockable?->cost ?? 0;

                        return number_format($available * $cost, 2);
                    }),

                TextColumn::make('stock_status')
                    ->label('Status')
                    ->badge()
                    ->state(function (Stock $record): string {
                        $threshold = Threshold::query()
                            ->where('stockable_type', Product::class)
                            ->where('stockable_id', $record->stockable_id)
                            ->where(function ($q) use ($record) {
                                $q->where('location_id', $record->location_id)
                                    ->orWhereNull('location_id');
                            })
                            ->first();

                        if (! $threshold || $threshold->min_quantity === null) {
                            return 'No Threshold';
                        }

                        $available = $record->quantity - $record->reserved_quantity;

                        return $available <= $threshold->min_quantity ? 'Low Stock' : 'OK';
                    })
                    ->color(function (Stock $record): string {
                        $threshold = Threshold::query()
                            ->where('stockable_type', Product::class)
                            ->where('stockable_id', $record->stockable_id)
                            ->where(function ($q) use ($record) {
                                $q->where('location_id', $record->location_id)
                                    ->orWhereNull('location_id');
                            })
                            ->first();

                        if (! $threshold || $threshold->min_quantity === null) {
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

                SelectFilter::make('category')
                    ->label('Category')
                    ->options(fn () => Category::query()->pluck('name', 'id'))
                    ->searchable()
                    ->query(function (Builder $query, array $data): Builder {
                        if (blank($data['value'])) {
                            return $query;
                        }

                        return $query->whereHasMorph('stockable', [Product::class],
                            fn (Builder $q) => $q->whereHas('categories', fn ($c) => $c->where('categories.id', $data['value']))
                        );
                    }),

                TernaryFilter::make('low_stock_only')
                    ->label('Low Stock Only')
                    ->queries(
                        true: fn (Builder $query) => $query->whereExists(function ($sub) {
                            $sub->from('inventorix_thresholds')
                                ->whereColumn('inventorix_thresholds.stockable_id', 'inventorix_stocks.stockable_id')
                                ->whereColumn('inventorix_thresholds.stockable_type', 'inventorix_stocks.stockable_type')
                                ->where(function ($q) {
                                    $q->whereColumn('inventorix_thresholds.location_id', 'inventorix_stocks.location_id')
                                        ->orWhereNull('inventorix_thresholds.location_id');
                                })
                                ->whereColumn('inventorix_stocks.quantity', '<=', 'inventorix_thresholds.min_quantity');
                        }),
                        false: fn (Builder $query) => $query,
                    ),
            ])
            ->defaultSort('stockable_id');
    }
}
