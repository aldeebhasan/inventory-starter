<?php

namespace App\Filament\Pages;

use Aldeebhasan\Inventorix\Models\Stock;
use Aldeebhasan\Inventorix\Models\Threshold;
use App\Filament\Exports\InventoriesExporter;
use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ExportAction;
use Filament\Forms\Components\TextInput;
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
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Query\Builder as QueryBuilder;
use UnitEnum;

class Inventories extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.inventories';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static string|UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Stocks';

    public function getTitle(): string|Htmlable
    {
        return 'Stocks';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'View current stock levels per product and location. Set min/max thresholds to trigger alerts.';
    }

    /**
     * Correlated subquery reading one column of the stock's location-specific threshold, so thresholds load with the page query instead of per row.
     */
    protected static function thresholdSubquery(string $column): QueryBuilder
    {
        return Threshold::query()
            ->select($column)
            ->whereColumn('inventorix_thresholds.stockable_type', 'inventorix_stocks.stockable_type')
            ->whereColumn('inventorix_thresholds.stockable_id', 'inventorix_stocks.stockable_id')
            ->whereColumn('inventorix_thresholds.location_id', 'inventorix_stocks.location_id')
            ->limit(1)
            ->toBase();
    }

    protected static function lowStockThresholdQuery(QueryBuilder $sub): void
    {
        $sub->from('inventorix_thresholds')
            ->whereColumn('inventorix_thresholds.stockable_id', 'inventorix_stocks.stockable_id')
            ->whereColumn('inventorix_thresholds.stockable_type', 'inventorix_stocks.stockable_type')
            ->where(function (QueryBuilder $q): void {
                $q->whereColumn('inventorix_thresholds.location_id', 'inventorix_stocks.location_id')
                    ->orWhereNull('inventorix_thresholds.location_id');
            })
            ->whereColumn('inventorix_stocks.quantity', '<=', 'inventorix_thresholds.min_quantity');
    }

    public static function formatThreshold(mixed $quantity): string
    {
        return $quantity !== null ? number_format((float) $quantity, 2) : '—';
    }

    /**
     * Status label computed from the threshold columns selected by the table query.
     */
    public static function stockStatus(Stock $record): string
    {
        $min = $record->getAttribute('threshold_min_quantity');

        if ($min === null) {
            return 'No Threshold';
        }

        return ($record->quantity - $record->reserved_quantity) <= (float) $min ? 'Low Stock' : 'OK';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Stock::query()
                    ->select('inventorix_stocks.*')
                    ->addSelect([
                        'threshold_min_quantity' => static::thresholdSubquery('min_quantity'),
                        'threshold_max_quantity' => static::thresholdSubquery('max_quantity'),
                    ])
                    ->with(['stockable' => fn (MorphTo $morphTo) => $morphTo->morphWith([Product::class => ['brand', 'categories']]), 'location'])
                    ->whereHasMorph('stockable', [Product::class])
            )
            ->headerActions([
                ExportAction::make()->exporter(InventoriesExporter::class),
            ])
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
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy(
                        Product::query()->select('name')->whereColumn('products.id', 'inventorix_stocks.stockable_id'),
                        $direction,
                    )),

                TextColumn::make('stockable.brand.name')
                    ->label('Brand')
                    ->placeholder('—'),

                TextColumn::make('stockable.categories')
                    ->label('Categories')
                    ->state(fn (Stock $record): string => $record->stockable?->getAttribute('categories')?->pluck('name')->join(', ') ?: '—')
                    ->toggleable(isToggledHiddenByDefault: true),

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

                TextColumn::make('valuation')
                    ->label('Valuation')
                    ->state(function (Stock $record): string {
                        $available = $record->quantity - $record->reserved_quantity;
                        $cost = $record->stockable?->getAttribute('cost') ?? 0;

                        return number_format($available * $cost, 2);
                    })
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('threshold_min')
                    ->label('Min Threshold')
                    ->state(fn (Stock $record): string => static::formatThreshold($record->getAttribute('threshold_min_quantity'))),

                TextColumn::make('threshold_max')
                    ->label('Max Threshold')
                    ->state(fn (Stock $record): string => static::formatThreshold($record->getAttribute('threshold_max_quantity'))),

                TextColumn::make('stock_status')
                    ->label('Status')
                    ->badge()
                    ->state(fn (Stock $record): string => static::stockStatus($record))
                    ->color(fn (string $state): string => match ($state) {
                        'Low Stock' => 'danger',
                        'OK' => 'success',
                        default => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('location_id')
                    ->label('Location')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => Location::query()->where('is_active', true)->where('name', 'like', "%{$search}%")->limit(50)->pluck('name', 'id'))
                    ->getOptionLabelUsing(fn ($value) => Location::find($value)?->name),

                SelectFilter::make('stockable_id')
                    ->label('Product')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => Product::query()->where('name', 'like', "%{$search}%")->limit(50)->pluck('name', 'id'))
                    ->getOptionLabelUsing(fn ($value) => Product::find($value)?->name)
                    ->query(function (Builder $query, array $data): Builder {
                        if (blank($data['value'])) {
                            return $query;
                        }

                        return $query->where('stockable_id', $data['value'])
                            ->where('stockable_type', Product::class);
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

                        return $query->whereHasMorph('stockable', [Product::class],
                            fn (Builder $q) => $q->whereHas('categories', fn ($c) => $c->where('categories.id', $data['value']))
                        );
                    }),

                TernaryFilter::make('low_stock_only')
                    ->label('Low Stock Only')
                    ->queries(
                        true: fn (Builder $query) => $query->whereExists(static::lowStockThresholdQuery(...)),
                        false: fn (Builder $query) => $query->whereNotExists(static::lowStockThresholdQuery(...)),
                    ),
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
