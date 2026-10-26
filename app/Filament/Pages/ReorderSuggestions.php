<?php

namespace App\Filament\Pages;

use Aldeebhasan\Inventorix\Enums\MovementType;
use Aldeebhasan\Inventorix\Facades\Inventorix;
use Aldeebhasan\Inventorix\Models\Movement;
use Aldeebhasan\Inventorix\Models\Stock;
use Aldeebhasan\Inventorix\Models\Threshold;
use App\Filament\Resources\PurchaseOrders\Pages\CreatePurchaseOrder;
use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use UnitEnum;

class ReorderSuggestions extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.reorder-suggestions';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Purchase Reports';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Reorder Suggestions';

    public function getTitle(): string|Htmlable
    {
        return 'Reorder Suggestions';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Low-stock products with suggested reorder quantities based on consumption velocity and thresholds.';
    }

    /** @var Collection<string, Movement>|null */
    protected ?Collection $velocityCache = null;

    /** @var Collection<string, Threshold>|null */
    protected ?Collection $thresholdCache = null;

    protected function getVelocities(): Collection
    {
        if ($this->velocityCache !== null) {
            return $this->velocityCache;
        }

        $this->velocityCache = Movement::query()
            ->where('type', MovementType::Deduct)
            ->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('stockable_id, location_id, SUM(quantity) as total_deducted')
            ->groupBy('stockable_id', 'location_id')
            ->get()
            ->keyBy(fn ($m) => $m->stockable_id.'-'.$m->location_id);

        return $this->velocityCache;
    }

    protected function getThresholds(): Collection
    {
        if ($this->thresholdCache !== null) {
            return $this->thresholdCache;
        }

        $this->thresholdCache = Threshold::query()
            ->where('stockable_type', Product::class)
            ->get()
            ->keyBy(fn ($t) => $t->stockable_id.'-'.($t->location_id ?? 'global'));

        return $this->thresholdCache;
    }

    protected function getThresholdFor(Stock $record): ?Threshold
    {
        $thresholds = $this->getThresholds();

        return $thresholds->get($record->stockable_id.'-'.$record->location_id)
            ?? $thresholds->get($record->stockable_id.'-global');
    }

    protected function getProduct(Stock $record): ?Product
    {
        return $record->stockable instanceof Product ? $record->stockable : null;
    }

    protected function getVelocityFor(Stock $record): ?object
    {
        return $this->getVelocities()->get($record->stockable_id.'-'.$record->location_id);
    }

    protected function getAvgDailyConsumption(Stock $record): float
    {
        $velocity = $this->getVelocityFor($record);
        if (! $velocity || $velocity->total_deducted <= 0) {
            return 0;
        }

        return $velocity->total_deducted / 30;
    }

    protected function getDaysUntilStockout(Stock $record): ?float
    {
        $avgDaily = $this->getAvgDailyConsumption($record);
        if ($avgDaily <= 0) {
            return null;
        }

        $available = $record->quantity - $record->reserved_quantity;

        return $available / $avgDaily;
    }

    protected function getSuggestedQty(Stock $record): int
    {
        $threshold = $this->getThresholdFor($record);
        $available = $record->quantity - $record->reserved_quantity;
        $avgDaily = $this->getAvgDailyConsumption($record);

        if ($threshold?->max_quantity) {
            $suggested = (float) $threshold->max_quantity - $available;
        } else {
            $suggested = ceil($avgDaily * 30);
        }

        $minDeficit = $threshold?->min_quantity ? (float) $threshold->min_quantity - $available : 0;

        return (int) max($suggested, $minDeficit, 0);
    }

    public function table(Table $table): Table
    {
        $lowStockIds = Inventorix::lowStockItems(stockableType: Product::class)->pluck('id');

        return $table
            ->query(
                Stock::query()
                    ->with(['stockable.brand', 'stockable.suppliers', 'location'])
                    ->whereIn('id', $lowStockIds)
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

                TextColumn::make('available')
                    ->label('Available')
                    ->numeric(decimalPlaces: 2)
                    ->state(fn (Stock $record): float => $record->quantity - $record->reserved_quantity),

                TextColumn::make('min_threshold')
                    ->label('Min Threshold')
                    ->state(function (Stock $record): string {
                        $threshold = $this->getThresholdFor($record);

                        return $threshold?->min_quantity !== null ? number_format((float) $threshold->min_quantity, 2) : '—';
                    }),

                TextColumn::make('avg_daily')
                    ->label('Avg Daily Consumption')
                    ->state(fn (Stock $record): string => number_format($this->getAvgDailyConsumption($record), 2)),

                TextColumn::make('days_until_stockout')
                    ->label('Days Until Stockout')
                    ->state(function (Stock $record): string {
                        $days = $this->getDaysUntilStockout($record);

                        return $days === null ? '∞' : (string) (int) $days;
                    }),

                TextColumn::make('suggested_qty')
                    ->label('Suggested Reorder Qty')
                    ->state(fn (Stock $record): string => (string) $this->getSuggestedQty($record)),

                TextColumn::make('preferred_supplier')
                    ->label('Preferred Supplier')
                    ->state(function (Stock $record): string {
                        $product = $this->getProduct($record);
                        $supplier = $product?->suppliers?->first();

                        return $supplier->name ?? '—';
                    }),

                TextColumn::make('supplier_cost')
                    ->label('Supplier Unit Cost')
                    ->state(function (Stock $record): string {
                        $product = $this->getProduct($record);
                        $supplier = $product?->suppliers?->first();
                        $cost = $supplier?->getAttribute('pivot')->unit_cost ?? $product->cost ?? 0;

                        return number_format((float) $cost, 3);
                    }),

                TextColumn::make('est_cost')
                    ->label('Est. Reorder Cost')
                    ->state(function (Stock $record): string {
                        $product = $this->getProduct($record);
                        $supplier = $product?->suppliers?->first();
                        $unitCost = $supplier?->getAttribute('pivot')->unit_cost ?? $product->cost ?? 0;
                        $qty = $this->getSuggestedQty($record);

                        return number_format($qty * (float) $unitCost, 2);
                    }),
            ])
            ->filters([
                SelectFilter::make('location_id')
                    ->label('Location')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => Location::query()->where('is_active', true)->where('name', 'like', "%{$search}%")->limit(50)->pluck('name', 'id'))
                    ->getOptionLabelUsing(fn ($value) => Location::find($value)?->name),

                SelectFilter::make('category')
                    ->label('Category')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => Category::query()->where('name', 'like', "%{$search}%")->limit(50)->pluck('name', 'id'))
                    ->getOptionLabelUsing(fn ($value) => Category::find($value)?->name)
                    ->query(function (Builder $query, array $data): Builder {
                        if (blank($data['value'])) {
                            return $query;
                        }

                        return $query->whereHasMorph('stockable', [Product::class],
                            fn (Builder $q) => $q->whereHas('categories', fn ($c) => $c->where('categories.id', $data['value']))
                        );
                    }),

                SelectFilter::make('urgency')
                    ->label('Urgency')
                    ->options([
                        'critical' => 'Critical (<7d)',
                        'warning' => 'Warning (<14d)',
                        'all' => 'All low stock',
                    ])
                    ->default('all')
                    ->query(fn (Builder $query, array $data): Builder => $query),
            ])
            ->recordActions([
                Action::make('create_purchase_order')
                    ->label('Create PO')
                    ->icon(Heroicon::OutlinedShoppingCart)
                    ->url(fn (Stock $record): string => CreatePurchaseOrder::getUrl([
                        'product_id' => $record->stockable_id,
                        'supplier_id' => $this->getProduct($record)?->suppliers->first()?->id,
                    ])),
            ])
            ->defaultSort('quantity', 'asc');
    }
}
