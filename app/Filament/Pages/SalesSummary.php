<?php

namespace App\Filament\Pages;

use App\Enums\SaleOrderStatus;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SaleOrderItem;
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

class SalesSummary extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.sales-summary';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBarSquare;

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    protected static ?string $navigationParentItem = 'Reports';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Sales Summary';

    public function getTitle(): string|Htmlable
    {
        return 'Sales Summary';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Revenue, cost, and margin breakdown per product from completed sales orders.';
    }

    protected function getDateFrom(): string
    {
        return $this->tableFilters['date_range']['from'] ?? now()->subDays(30)->toDateString();
    }

    protected function getDateUntil(): string
    {
        return $this->tableFilters['date_range']['until'] ?? now()->toDateString();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                SaleOrderItem::query()
                    ->join('sale_orders', 'sale_orders.id', '=', 'sale_order_items.sale_order_id')
                    ->join('products', 'products.id', '=', 'sale_order_items.product_id')
                    ->whereIn('sale_orders.status', [
                        SaleOrderStatus::Confirmed,
                        SaleOrderStatus::Shipped,
                        SaleOrderStatus::Picked,
                        SaleOrderStatus::Fulfilled,
                    ])
                    ->selectRaw('
                        sale_order_items.product_id as id,
                        sale_order_items.product_id,
                        SUM(sale_order_items.quantity) as qty_sold,
                        AVG(sale_order_items.unit_price) as avg_unit_price,
                        SUM(sale_order_items.quantity * sale_order_items.unit_price) as total_revenue,
                        SUM(sale_order_items.quantity * products.cost) as total_cost
                    ')
                    ->groupBy('sale_order_items.product_id')
            )
            ->columns([
                TextColumn::make('product_name')
                    ->label('Product')
                    ->state(fn (SaleOrderItem $record): string => Product::find($record->product_id)->name ?? '—')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHas('product', fn ($q) => $q->where('name', 'like', "%{$search}%"));
                    }),

                TextColumn::make('brand_name')
                    ->label('Brand')
                    ->state(fn (SaleOrderItem $record): string => Product::find($record->product_id)?->brand->name ?? '—'),

                TextColumn::make('category_name')
                    ->label('Category')
                    ->state(fn (SaleOrderItem $record): string => Product::find($record->product_id)?->categories?->first()->name ?? '—'),

                TextColumn::make('qty_sold')
                    ->label('Qty Sold')
                    ->numeric(decimalPlaces: 3)
                    ->sortable(),

                TextColumn::make('avg_unit_price')
                    ->label('Avg Unit Price')
                    ->numeric(decimalPlaces: 3)
                    ->sortable(),

                TextColumn::make('total_revenue')
                    ->label('Total Revenue')
                    ->numeric(decimalPlaces: 2)
                    ->money('USD')
                    ->sortable(),

                TextColumn::make('total_cost')
                    ->label('Total Cost')
                    ->numeric(decimalPlaces: 2)
                    ->money('USD')
                    ->sortable(),

                TextColumn::make('gross_margin')
                    ->label('Gross Margin')
                    ->state(fn (SaleOrderItem $record): string => number_format(
                        (float) $record->getAttribute('total_revenue') - (float) $record->getAttribute('total_cost'), 2
                    ))
                    ->money('USD'),

                TextColumn::make('margin_pct')
                    ->label('Margin %')
                    ->state(function (SaleOrderItem $record): string {
                        $revenue = (float) $record->getAttribute('total_revenue');
                        if ($revenue <= 0) {
                            return '0.0%';
                        }
                        $margin = $revenue - (float) $record->getAttribute('total_cost');

                        return number_format(($margin / $revenue) * 100, 1).'%';
                    })
                    ->color(function (SaleOrderItem $record): string {
                        $revenue = (float) $record->getAttribute('total_revenue');
                        if ($revenue <= 0) {
                            return 'gray';
                        }
                        $pct = (($revenue - (float) $record->getAttribute('total_cost')) / $revenue) * 100;

                        if ($pct > 20) {
                            return 'success';
                        }
                        if ($pct < 0) {
                            return 'danger';
                        }

                        return 'gray';
                    })
                    ->badge(),
            ])
            ->filters([
                Filter::make('date_range')
                    ->label('Date Range')
                    ->form([
                        DatePicker::make('from')
                            ->label('From')
                            ->default(now()->subDays(30)),
                        DatePicker::make('until')
                            ->label('Until')
                            ->default(now()),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'], fn (Builder $q) => $q->whereDate('sale_orders.created_at', '>=', $data['from']))
                            ->when($data['until'], fn (Builder $q) => $q->whereDate('sale_orders.created_at', '<=', $data['until']));
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

                SelectFilter::make('product_id')
                    ->label('Product')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => Product::query()->where('name', 'like', "%{$search}%")->limit(50)->pluck('name', 'id'))
                    ->getOptionLabelUsing(fn ($value) => Product::find($value)?->name)
                    ->query(function (Builder $query, array $data): Builder {
                        if (blank($data['value'])) {
                            return $query;
                        }

                        return $query->where('sale_order_items.product_id', $data['value']);
                    }),

                SelectFilter::make('customer_id')
                    ->label('Customer')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => Customer::query()->where('name', 'like', "%{$search}%")->limit(50)->pluck('name', 'id'))
                    ->getOptionLabelUsing(fn ($value) => Customer::find($value)?->name)
                    ->query(function (Builder $query, array $data): Builder {
                        if (blank($data['value'])) {
                            return $query;
                        }

                        return $query->where('sale_orders.customer_id', $data['value']);
                    }),

                SelectFilter::make('category')
                    ->label('Category')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => Category::query()->where('name', 'like', "%{$search}%")->limit(50)->pluck('name', 'id'))
                    ->getOptionLabelUsing(fn ($value) => Category::find($value)?->name)
                    ->query(function (Builder $query, array $data): Builder {
                        if (blank($data['value'])) {
                            return $query;
                        }

                        return $query->whereIn('sale_order_items.product_id',
                            Product::query()
                                ->whereHas('categories', fn ($c) => $c->where('categories.id', $data['value']))
                                ->pluck('id')
                        );
                    }),
            ])
            ->defaultSort('total_revenue', 'desc');
    }
}
