<?php

namespace App\Filament\Pages;

use Aldeebhasan\Inventorix\Models\Stock;
use App\Models\Brand;
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
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class InventoryValuation extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.inventory-valuation';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyDollar;

    protected static string|UnitEnum|null $navigationGroup = 'Inventory Reports';

    protected static ?int $navigationSort = 9;

    protected static ?string $navigationLabel = 'Inventory Valuation';

    public function getTitle(): string|Htmlable
    {
        return 'Inventory Valuation';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Financial value of on-hand inventory by product and location, with cost breakdown and total share.';
    }

    protected function getHeaderWidgets(): array
    {
        return [
            InventoryValuationStats::class,
        ];
    }

    public function table(Table $table): Table
    {
        $grandTotal = Stock::query()
            ->whereHasMorph('stockable', [Product::class])
            ->where('quantity', '>', 0)
            ->get()
            ->sum(function (Stock $stock) {
                $available = $stock->quantity - $stock->reserved_quantity;

                return $available * ($stock->stockable?->cost ?? 0);
            });

        return $table
            ->query(
                Stock::query()
                    ->with(['stockable.brand', 'stockable.categories', 'location'])
                    ->whereHasMorph('stockable', [Product::class])
                    ->where('quantity', '>', 0)
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

                TextColumn::make('category')
                    ->label('Category')
                    ->state(fn (Stock $record): string => $record->stockable?->categories?->first()?->name ?? '—'),

                TextColumn::make('location.name')
                    ->label('Location')
                    ->sortable(),

                TextColumn::make('quantity')
                    ->label('Qty On Hand')
                    ->numeric(decimalPlaces: 2)
                    ->sortable(),

                TextColumn::make('available')
                    ->label('Available')
                    ->numeric(decimalPlaces: 2)
                    ->state(fn (Stock $record): float => $record->quantity - $record->reserved_quantity),

                TextColumn::make('avg_cost')
                    ->label('Avg Cost/Unit')
                    ->state(fn (Stock $record): string => number_format($record->stockable?->cost ?? 0, 2)),

                TextColumn::make('total_value')
                    ->label('Total Value')
                    ->state(function (Stock $record): string {
                        $available = $record->quantity - $record->reserved_quantity;

                        return number_format($available * ($record->stockable?->cost ?? 0), 2);
                    })
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query->orderByRaw('((quantity - reserved_quantity) * (SELECT cost FROM products WHERE products.id = inventorix_stocks.stockable_id)) '.$direction);
                    }),

                TextColumn::make('pct_of_total')
                    ->label('% of Total')
                    ->state(function (Stock $record) use ($grandTotal): string {
                        if ($grandTotal <= 0) {
                            return '0.00%';
                        }

                        $available = $record->quantity - $record->reserved_quantity;
                        $value = $available * ($record->stockable?->cost ?? 0);

                        return number_format(($value / $grandTotal) * 100, 2).'%';
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

                SelectFilter::make('brand')
                    ->label('Brand')
                    ->options(fn () => Brand::query()->pluck('name', 'id'))
                    ->searchable()
                    ->query(function (Builder $query, array $data): Builder {
                        if (blank($data['value'])) {
                            return $query;
                        }

                        return $query->whereHasMorph('stockable', [Product::class],
                            fn (Builder $q) => $q->where('brand_id', $data['value'])
                        );
                    }),
            ])
            ->defaultSort('quantity', 'desc');
    }
}
