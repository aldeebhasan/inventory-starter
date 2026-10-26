<?php

namespace App\Filament\Pages;

use App\Enums\ReturnOrderStatus;
use App\Enums\ReturnOrderType;
use App\Enums\SaleOrderStatus;
use App\Filament\Exports\CustomerReturnsAnalysisExporter;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SaleOrderItem;
use BackedEnum;
use Filament\Actions\ExportAction;
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

class CustomerReturnsAnalysis extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.customer-returns-analysis';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUturnLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    protected static ?string $navigationParentItem = 'Reports';

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

    protected function getDateFrom(): ?string
    {
        return $this->tableFilters['date_range']['from'] ?? null;
    }

    protected function getDateUntil(): ?string
    {
        return $this->tableFilters['date_range']['until'] ?? null;
    }

    /**
     * Correlated SUM of shipped/picked sales for the row's product within the active date range.
     *
     * @return Builder<SaleOrderItem>
     */
    protected function qtySoldSubquery(): Builder
    {
        $dateFrom = $this->getDateFrom();
        $dateUntil = $this->getDateUntil();

        return SaleOrderItem::query()
            ->join('sale_orders', 'sale_orders.id', '=', 'sale_order_items.sale_order_id')
            ->whereColumn('sale_order_items.product_id', 'products.id')
            ->whereIn('sale_orders.status', [SaleOrderStatus::Shipped, SaleOrderStatus::Picked])
            ->when($dateFrom, fn (Builder $q) => $q->whereDate('sale_orders.created_at', '>=', $dateFrom))
            ->when($dateUntil, fn (Builder $q) => $q->whereDate('sale_orders.created_at', '<=', $dateUntil))
            ->selectRaw('COALESCE(SUM(sale_order_items.quantity), 0)');
    }

    public function table(Table $table): Table
    {
        return $table
            // Closure: evaluated per request so the qty_sold subquery sees the current date filter.
            // Grouped on products.id so the record key is the product: rows can be resolved/exported by key.
            // Every selected product column is listed in GROUP BY: MariaDB (ONLY_FULL_GROUP_BY) rejects products.* grouped by id alone.
            ->query(fn (): Builder => Product::withTrashed()
                ->join('return_order_items', 'return_order_items.product_id', '=', 'products.id')
                ->join('return_orders', 'return_orders.id', '=', 'return_order_items.return_order_id')
                ->where('return_orders.type', ReturnOrderType::CustomerReturn)
                ->where('return_orders.status', ReturnOrderStatus::Completed)
                ->select(['products.id', 'products.name', 'products.brand_id'])
                ->selectRaw('
                    SUM(return_order_items.quantity) as qty_returned,
                    SUM(return_order_items.quantity * COALESCE(return_order_items.price, 0)) as refund_amount
                ')
                ->selectSub($this->qtySoldSubquery(), 'qty_sold')
                ->groupBy('products.id', 'products.name', 'products.brand_id'))
            ->headerActions([
                ExportAction::make()
                    ->exporter(CustomerReturnsAnalysisExporter::class),
            ])
            ->columns([
                TextColumn::make('name')
                    ->label('Product')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('products.name', 'like', "%{$search}%")),

                TextColumn::make('qty_returned')
                    ->label('Qty Returned')
                    ->numeric(decimalPlaces: 3)
                    ->sortable(),

                TextColumn::make('qty_sold')
                    ->label('Qty Sold (Period)')
                    ->numeric(decimalPlaces: 3)
                    ->sortable(),

                TextColumn::make('return_rate')
                    ->label('Return Rate')
                    ->badge()
                    ->state(fn (Product $record): string => ($rate = self::returnRate($record)) === null ? '—' : number_format($rate, 1).'%')
                    ->color(fn (Product $record): string => (self::returnRate($record) ?? 0) > 10 ? 'danger' : 'gray'),

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
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => Customer::query()->where('name', 'like', "%{$search}%")->limit(50)->pluck('name', 'id'))
                    ->getOptionLabelUsing(fn ($value) => Customer::find($value)?->name)
                    ->query(function (Builder $query, array $data): Builder {
                        if (blank($data['value'])) {
                            return $query;
                        }

                        return $query->where('return_orders.customer_id', $data['value']);
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

                        return $query->where('products.id', $data['value']);
                    }),
            ])
            ->defaultSort('qty_returned', 'desc');
    }

    /**
     * Returned qty as a percentage of qty sold in the period, or null when nothing was sold.
     */
    public static function returnRate(Product $record): ?float
    {
        $qtySold = (float) $record->getAttribute('qty_sold');

        if ($qtySold <= 0) {
            return null;
        }

        return ((float) $record->getAttribute('qty_returned') / $qtySold) * 100;
    }
}
