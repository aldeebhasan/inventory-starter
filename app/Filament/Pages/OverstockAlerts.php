<?php

namespace App\Filament\Pages;

use Aldeebhasan\Inventorix\Models\Stock;
use Aldeebhasan\Inventorix\Models\Threshold;
use App\Filament\Exports\OverstockAlertsExporter;
use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
use BackedEnum;
use Filament\Actions\ExportAction;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Query\Builder as QueryBuilder;
use UnitEnum;

class OverstockAlerts extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.overstock-alerts';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationCircle;

    protected static string|UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?string $navigationParentItem = 'Reports';

    protected static ?int $navigationSort = 15;

    protected static ?string $navigationLabel = 'Overstock Alerts';

    public function getTitle(): string|Htmlable
    {
        return 'Overstock Alerts';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Products exceeding their maximum threshold — identify excess inventory tying up capital.';
    }

    /**
     * Max quantity of the stock's threshold, preferring the location-specific one over the global one.
     */
    protected static function maxThresholdSubquery(): QueryBuilder
    {
        return Threshold::query()
            ->select('max_quantity')
            ->whereColumn('inventorix_thresholds.stockable_type', 'inventorix_stocks.stockable_type')
            ->whereColumn('inventorix_thresholds.stockable_id', 'inventorix_stocks.stockable_id')
            ->where(function (Builder $q): void {
                $q->whereColumn('inventorix_thresholds.location_id', 'inventorix_stocks.location_id')
                    ->orWhereNull('inventorix_thresholds.location_id');
            })
            ->whereNotNull('max_quantity')
            ->orderByRaw('inventorix_thresholds.location_id is null')
            ->limit(1)
            ->toBase();
    }

    public static function surplus(Stock $record): ?float
    {
        $max = $record->getAttribute('max_threshold_quantity');

        if ($max === null) {
            return null;
        }

        return max(0.0, ($record->quantity - $record->reserved_quantity) - (float) $max);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Stock::query()
                    ->select('inventorix_stocks.*')
                    ->addSelect(['max_threshold_quantity' => static::maxThresholdSubquery()])
                    ->with(['stockable' => fn (MorphTo $morphTo) => $morphTo->morphWith([Product::class => ['brand']]), 'location'])
                    ->whereHasMorph('stockable', [Product::class])
                    ->where('quantity', '>', 0)
                    ->whereRaw('quantity - reserved_quantity > (
                        SELECT COALESCE(MAX(max_quantity), quantity + 1) FROM inventorix_thresholds
                        WHERE inventorix_thresholds.stockable_id = inventorix_stocks.stockable_id
                        AND inventorix_thresholds.stockable_type = inventorix_stocks.stockable_type
                        AND (inventorix_thresholds.location_id = inventorix_stocks.location_id OR inventorix_thresholds.location_id IS NULL)
                        AND inventorix_thresholds.max_quantity IS NOT NULL
                    )')
            )
            ->headerActions([
                ExportAction::make()->exporter(OverstockAlertsExporter::class),
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
                    }),

                TextColumn::make('stockable.brand.name')
                    ->label('Brand')
                    ->placeholder('—'),

                TextColumn::make('location.name')
                    ->label('Location'),

                TextColumn::make('quantity')
                    ->label('On Hand')
                    ->numeric(decimalPlaces: 2),

                TextColumn::make('reserved_quantity')
                    ->label('Reserved')
                    ->numeric(decimalPlaces: 2),

                TextColumn::make('available')
                    ->label('Available')
                    ->state(fn (Stock $record): string => number_format($record->quantity - $record->reserved_quantity, 2)),

                TextColumn::make('max_threshold')
                    ->label('Max Threshold')
                    ->state(fn (Stock $record): string => Inventories::formatThreshold($record->getAttribute('max_threshold_quantity'))),

                TextColumn::make('surplus')
                    ->label('Surplus')
                    ->badge()
                    ->color('warning')
                    ->state(function (Stock $record): string {
                        $surplus = static::surplus($record);

                        return $surplus === null ? '—' : number_format($surplus, 2);
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
            ])
            ->defaultSort('quantity', 'desc');
    }
}
