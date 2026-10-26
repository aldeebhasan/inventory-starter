<?php

namespace App\Filament\Pages;

use App\Enums\PurchaseOrderStatus;
use App\Filament\Exports\PurchaseCostAnalysisExporter;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Models\Product;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use BackedEnum;
use Filament\Actions\ExportAction;
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
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use UnitEnum;

class PurchaseCostAnalysis extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.purchase-cost-analysis';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected static string|UnitEnum|null $navigationGroup = 'Purchase';

    protected static ?string $navigationParentItem = 'Reports';

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

    /**
     * Correlated lookup of the unit cost on the previous confirmed/received PO for the same product and supplier
     * (ordered before the row's PO), so the trend needs no per-row query.
     */
    protected static function previousUnitCostSubquery(): QueryBuilder
    {
        return DB::table('purchase_order_items as prev_items')
            ->join('purchase_orders as prev_orders', 'prev_orders.id', '=', 'prev_items.purchase_order_id')
            ->join('purchase_orders as cur_orders', function (JoinClause $join): void {
                $join->on('cur_orders.supplier_id', '=', 'prev_orders.supplier_id')
                    ->on('prev_orders.ordered_at', '<', 'cur_orders.ordered_at');
            })
            ->whereColumn('cur_orders.id', 'purchase_order_items.purchase_order_id')
            ->whereColumn('prev_items.product_id', 'purchase_order_items.product_id')
            ->whereIn('prev_orders.status', [PurchaseOrderStatus::Confirmed, PurchaseOrderStatus::Received])
            ->whereNull('prev_orders.deleted_at')
            ->orderByDesc('prev_items.purchase_order_id')
            ->limit(1)
            ->select('prev_items.unit_cost');
    }

    /**
     * 'up', 'down' or 'flat' versus the previous unit cost from the same supplier.
     */
    public static function costTrend(PurchaseOrderItem $record): string
    {
        $previous = $record->getAttribute('previous_unit_cost');

        if ($previous === null) {
            return 'flat';
        }

        return match ($record->unit_cost <=> (float) $previous) {
            1 => 'up',
            -1 => 'down',
            default => 'flat',
        };
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                PurchaseOrderItem::query()
                    ->with(['purchaseOrder.supplier', 'product'])
                    ->select('purchase_order_items.*')
                    ->selectSub(self::previousUnitCostSubquery(), 'previous_unit_cost')
                    ->whereHas('purchaseOrder', fn (Builder $q) => $q
                        ->whereIn('status', [
                            PurchaseOrderStatus::Confirmed,
                            PurchaseOrderStatus::Received,
                        ])
                    )
            )
            ->headerActions([
                ExportAction::make()
                    ->exporter(PurchaseCostAnalysisExporter::class),
            ])
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
                    ->state(fn (PurchaseOrderItem $record): string => self::costTrend($record))
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
