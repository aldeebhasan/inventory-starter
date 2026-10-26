<?php

namespace App\Filament\Pages;

use App\Enums\PurchaseOrderStatus;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
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
use Illuminate\Support\Facades\DB;
use UnitEnum;

class SupplierPerformance extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.supplier-performance';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected static string|UnitEnum|null $navigationGroup = 'Purchase';

    protected static ?string $navigationParentItem = 'Reports';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Supplier Performance';

    public function getTitle(): string|Htmlable
    {
        return 'Supplier Performance';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Compare suppliers by fulfillment rate, lead time, total spend, and order volume.';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Supplier::query()
                    ->withCount([
                        'purchaseOrders as total_pos',
                        'purchaseOrders as received_pos' => fn ($q) => $q->where('status', PurchaseOrderStatus::Received),
                        'purchaseOrders as cancelled_pos' => fn ($q) => $q->where('status', PurchaseOrderStatus::Cancelled),
                    ])
                    ->addSelect([
                        'total_spend' => PurchaseOrderItem::query()
                            ->selectRaw('SUM(purchase_order_items.quantity * purchase_order_items.unit_cost)')
                            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
                            ->where('purchase_orders.status', PurchaseOrderStatus::Received)
                            ->whereColumn('purchase_orders.supplier_id', 'suppliers.id'),
                        'total_items' => PurchaseOrderItem::query()
                            ->selectRaw('SUM(purchase_order_items.quantity)')
                            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
                            ->where('purchase_orders.status', PurchaseOrderStatus::Received)
                            ->whereColumn('purchase_orders.supplier_id', 'suppliers.id'),
                        'avg_lead_time' => PurchaseOrder::query()
                            ->selectRaw(DB::getDriverName() === 'sqlite'
                                ? 'AVG(CAST(julianday(updated_at) - julianday(ordered_at) AS INTEGER))'
                                : 'AVG(DATEDIFF(updated_at, ordered_at))')
                            ->whereColumn('supplier_id', 'suppliers.id')
                            ->where('status', PurchaseOrderStatus::Received),
                    ])
            )
            ->columns([
                TextColumn::make('name')
                    ->label('Supplier')
                    ->searchable()
                    ->sortable()
                    ->url(fn (Supplier $record) => SupplierResource::getUrl('view', ['record' => $record->id])),

                TextColumn::make('total_pos')
                    ->label('Total POs')
                    ->sortable(),

                TextColumn::make('received_pos')
                    ->label('Received')
                    ->sortable(),

                TextColumn::make('cancelled_pos')
                    ->label('Cancelled')
                    ->sortable(),

                TextColumn::make('fulfillment_rate')
                    ->label('Fulfillment Rate')
                    ->state(function (Supplier $record): string {
                        $total = (int) $record->getAttribute('total_pos');
                        if ($total === 0) {
                            return '0.0%';
                        }

                        return number_format(((int) $record->getAttribute('received_pos') / $total) * 100, 1).'%';
                    }),

                TextColumn::make('avg_lead_time')
                    ->label('Avg Lead Time')
                    ->state(function (Supplier $record): string {
                        if ($record->getAttribute('avg_lead_time') === null) {
                            return '—';
                        }

                        return number_format((float) $record->getAttribute('avg_lead_time'), 1).' days';
                    }),

                TextColumn::make('total_items')
                    ->label('Total Items')
                    ->state(fn (Supplier $record): string => number_format((float) ($record->getAttribute('total_items') ?? 0), 0)),

                TextColumn::make('total_spend')
                    ->label('Total Spend')
                    ->state(fn (Supplier $record): string => number_format((float) ($record->getAttribute('total_spend') ?? 0), 2))
                    ->money('USD')
                    ->sortable(),

                TextColumn::make('avg_unit_cost')
                    ->label('Avg Unit Cost')
                    ->state(function (Supplier $record): string {
                        $items = (float) ($record->getAttribute('total_items') ?? 0);
                        if ($items <= 0) {
                            return '—';
                        }

                        return number_format((float) $record->getAttribute('total_spend') / $items, 3);
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
                            ->when($data['from'], fn (Builder $q) => $q->whereHas('purchaseOrders',
                                fn (Builder $po) => $po->whereDate('ordered_at', '>=', $data['from'])))
                            ->when($data['until'], fn (Builder $q) => $q->whereHas('purchaseOrders',
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

                SelectFilter::make('supplier_id')
                    ->label('Supplier')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => Supplier::query()->where('name', 'like', "%{$search}%")->limit(50)->pluck('name', 'id'))
                    ->getOptionLabelUsing(fn ($value) => Supplier::find($value)?->name)
                    ->query(function (Builder $query, array $data): Builder {
                        if (blank($data['value'])) {
                            return $query;
                        }

                        return $query->where('id', $data['value']);
                    }),
            ])
            ->defaultSort('total_spend', 'desc');
    }
}
