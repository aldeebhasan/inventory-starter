<?php

namespace App\Filament\Pages;

use App\Enums\ReturnOrderStatus;
use App\Enums\ReturnOrderType;
use App\Enums\SaleOrderStatus;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ReturnOrderItem;
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
use Illuminate\Support\Collection;
use UnitEnum;

class CustomerReturnsAnalysis extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.customer-returns-analysis';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUturnLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Sales Reports';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Returns Analysis';

    public function getTitle(): string|Htmlable
    {
        return 'Customer Returns Analysis';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Return volumes and rates per product — identify quality issues or problematic SKUs.';
    }

    /** @var Collection<int, object>|null */
    protected ?Collection $salesCache = null;

    protected function getDateFrom(): ?string
    {
        return $this->tableFilters['date_range']['from'] ?? null;
    }

    protected function getDateUntil(): ?string
    {
        return $this->tableFilters['date_range']['until'] ?? null;
    }

    protected function getSalesAggregates(): Collection
    {
        if ($this->salesCache !== null) {
            return $this->salesCache;
        }

        $query = SaleOrderItem::query()
            ->join('sale_orders', 'sale_orders.id', '=', 'sale_order_items.sale_order_id')
            ->whereIn('sale_orders.status', [SaleOrderStatus::Shipped, SaleOrderStatus::Picked])
            ->selectRaw('sale_order_items.product_id, SUM(sale_order_items.quantity) as qty_sold')
            ->groupBy('sale_order_items.product_id');

        $dateFrom = $this->getDateFrom();
        $dateUntil = $this->getDateUntil();

        if ($dateFrom) {
            $query->whereDate('sale_orders.created_at', '>=', $dateFrom);
        }
        if ($dateUntil) {
            $query->whereDate('sale_orders.created_at', '<=', $dateUntil);
        }

        $this->salesCache = $query->get()->keyBy('product_id');

        return $this->salesCache;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                ReturnOrderItem::query()
                    ->join('return_orders', 'return_orders.id', '=', 'return_order_items.return_order_id')
                    ->where('return_orders.type', ReturnOrderType::CustomerReturn)
                    ->where('return_orders.status', ReturnOrderStatus::Completed)
                    ->selectRaw('
                        return_order_items.product_id as id,
                        return_order_items.product_id,
                        SUM(return_order_items.quantity) as qty_returned,
                        SUM(return_order_items.quantity * COALESCE(return_order_items.price, 0)) as refund_amount
                    ')
                    ->groupBy('return_order_items.product_id')
            )
            ->columns([
                TextColumn::make('product_name')
                    ->label('Product')
                    ->state(fn (ReturnOrderItem $record): string => Product::find($record->product_id)?->name ?? '—')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereIn('return_order_items.product_id',
                            Product::where('name', 'like', "%{$search}%")->pluck('id')
                        );
                    }),

                TextColumn::make('qty_returned')
                    ->label('Qty Returned')
                    ->numeric(decimalPlaces: 3)
                    ->sortable(),

                TextColumn::make('qty_sold')
                    ->label('Qty Sold (Period)')
                    ->state(function (ReturnOrderItem $record): string {
                        $sales = $this->getSalesAggregates()->get($record->product_id);

                        return number_format((float) ($sales?->qty_sold ?? 0), 3);
                    }),

                TextColumn::make('return_rate')
                    ->label('Return Rate')
                    ->badge()
                    ->state(function (ReturnOrderItem $record): string {
                        $sales = $this->getSalesAggregates()->get($record->product_id);
                        $qtySold = (float) ($sales?->qty_sold ?? 0);

                        if ($qtySold <= 0) {
                            return '—';
                        }

                        return number_format(((float) $record->qty_returned / $qtySold) * 100, 1).'%';
                    })
                    ->color(function (ReturnOrderItem $record): string {
                        $sales = $this->getSalesAggregates()->get($record->product_id);
                        $qtySold = (float) ($sales?->qty_sold ?? 0);

                        if ($qtySold <= 0) {
                            return 'gray';
                        }

                        $rate = ((float) $record->qty_returned / $qtySold) * 100;

                        return $rate > 10 ? 'danger' : 'gray';
                    }),

                TextColumn::make('refund_amount')
                    ->label('Refund Amount')
                    ->numeric(decimalPlaces: 2)
                    ->money('USD')
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
                            ->when($data['from'], fn (Builder $q) => $q->whereDate('return_orders.created_at', '>=', $data['from']))
                            ->when($data['until'], fn (Builder $q) => $q->whereDate('return_orders.created_at', '<=', $data['until']));
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

                SelectFilter::make('customer_id')
                    ->label('Customer')
                    ->options(fn () => Customer::query()->pluck('name', 'id'))
                    ->searchable()
                    ->query(function (Builder $query, array $data): Builder {
                        if (blank($data['value'])) {
                            return $query;
                        }

                        return $query->where('return_orders.customer_id', $data['value']);
                    }),

                SelectFilter::make('product_id')
                    ->label('Product')
                    ->options(fn () => Product::query()->pluck('name', 'id'))
                    ->searchable()
                    ->query(function (Builder $query, array $data): Builder {
                        if (blank($data['value'])) {
                            return $query;
                        }

                        return $query->where('return_order_items.product_id', $data['value']);
                    }),
            ])
            ->defaultSort('qty_returned', 'desc');
    }
}
