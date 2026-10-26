<?php

namespace App\Filament\Pages;

use App\Enums\PurchaseOrderStatus;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Models\Product;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
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

class PurchaseCostAnalysis extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.purchase-cost-analysis';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected static string|UnitEnum|null $navigationGroup = 'Purchase Reports';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Cost Analysis';

    public function getTitle(): string|Htmlable
    {
        return 'Purchase Cost Analysis';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Track unit costs across purchase orders and suppliers, with trend indicators showing price changes over time.';
    }

    /** @var Collection<string, float>|null */
    protected ?Collection $previousCostCache = null;

    protected function getPreviousCosts(): Collection
    {
        if ($this->previousCostCache !== null) {
            return $this->previousCostCache;
        }

        $this->previousCostCache = collect();

        return $this->previousCostCache;
    }

    protected function getPreviousCostFor(PurchaseOrderItem $item): ?float
    {
        $key = $item->product_id.'-'.($item->purchaseOrder->supplier_id ?? 0);
        $cache = $this->getPreviousCosts();

        if (! $cache->has($key)) {
            $previousCost = PurchaseOrderItem::query()
                ->where('product_id', $item->product_id)
                ->whereHas('purchaseOrder', fn (Builder $q) => $q
                    ->where('supplier_id', $item->purchaseOrder->supplier_id)
                    ->where('ordered_at', '<', $item->purchaseOrder->ordered_at)
                    ->whereIn('status', [PurchaseOrderStatus::Confirmed, PurchaseOrderStatus::Received])
                )
                ->orderByDesc('purchase_order_id')
                ->value('unit_cost');

            $cache->put($key, $previousCost);
        }

        return $cache->get($key);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                PurchaseOrderItem::query()
                    ->with(['purchaseOrder.supplier', 'product'])
                    ->whereHas('purchaseOrder', fn (Builder $q) => $q
                        ->whereIn('status', [
                            PurchaseOrderStatus::Confirmed,
                            PurchaseOrderStatus::Received,
                        ])
                    )
            )
            ->columns([
                TextColumn::make('product.name')
                    ->label('Product')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('purchaseOrder.supplier.name')
                    ->label('Supplier'),

                TextColumn::make('purchaseOrder.order_number')
                    ->label('PO #')
                    ->url(fn (PurchaseOrderItem $record) => PurchaseOrderResource::getUrl('view', ['record' => $record->purchase_order_id])),

                TextColumn::make('purchaseOrder.ordered_at')
                    ->label('Ordered Date')
                    ->date()
                    ->sortable(),

                TextColumn::make('unit_cost')
                    ->label('Unit Cost')
                    ->numeric(decimalPlaces: 3)
                    ->sortable(),

                TextColumn::make('quantity')
                    ->label('Quantity')
                    ->numeric(decimalPlaces: 3),

                TextColumn::make('total_cost')
                    ->label('Total Cost')
                    ->state(fn (PurchaseOrderItem $record): string => number_format($record->unit_cost * $record->quantity, 2))
                    ->sortable(query: fn (Builder $query, string $direction) => $query
                        ->orderByRaw('unit_cost * quantity '.$direction)),

                IconColumn::make('cost_trend')
                    ->label('Trend')
                    ->state(function (PurchaseOrderItem $record): string {
                        $previous = $this->getPreviousCostFor($record);
                        if ($previous === null) {
                            return 'flat';
                        }

                        if ($record->unit_cost > $previous) {
                            return 'up';
                        }
                        if ($record->unit_cost < $previous) {
                            return 'down';
                        }

                        return 'flat';
                    })
                    ->icon(fn (string $state): Heroicon => match ($state) {
                        'up' => Heroicon::ArrowUp,
                        'down' => Heroicon::ArrowDown,
                        default => Heroicon::Minus,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'up' => 'danger',
                        'down' => 'success',
                        default => 'gray',
                    }),
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
                            ->when($data['from'], fn (Builder $q) => $q->whereHas('purchaseOrder',
                                fn (Builder $po) => $po->whereDate('ordered_at', '>=', $data['from'])))
                            ->when($data['until'], fn (Builder $q) => $q->whereHas('purchaseOrder',
                                fn (Builder $po) => $po->whereDate('ordered_at', '<=', $data['until'])));
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

                        return $query->where('product_id', $data['value']);
                    }),

                SelectFilter::make('supplier_id')
                    ->label('Supplier')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => Supplier::query()->where('name', 'like', "%{$search}%")->limit(50)->pluck('name', 'id'))
                    ->getOptionLabelUsing(fn ($value) => Supplier::find($value)?->name)
                    ->query(function (Builder $query, array $data): Builder {
                        if (blank($data['value'])) {
                            return $query;
                        }

                        return $query->whereHas('purchaseOrder',
                            fn (Builder $po) => $po->where('supplier_id', $data['value']));
                    }),
            ])
            ->defaultSort('purchase_order_items.id', 'desc');
    }
}
