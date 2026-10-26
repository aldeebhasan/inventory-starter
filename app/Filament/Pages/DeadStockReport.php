<?php

namespace App\Filament\Pages;

use Aldeebhasan\Inventorix\Enums\MovementType;
use Aldeebhasan\Inventorix\Models\Movement;
use Aldeebhasan\Inventorix\Models\Stock;
use App\Filament\Exports\DeadStockReportExporter;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
use BackedEnum;
use Carbon\Carbon;
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
use UnitEnum;

class DeadStockReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.dead-stock-report';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBoxXMark;

    protected static string|UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?string $navigationParentItem = 'Reports';

    protected static ?int $navigationSort = 14;

    protected static ?string $navigationLabel = 'Dead Stock';

    public function getTitle(): string|Htmlable
    {
        return 'Dead Stock Report';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Products with no deductions for a configurable period — candidates for clearance or write-off.';
    }

    protected function getMinDaysInactive(): int
    {
        return (int) ($this->tableFilters['min_days_inactive']['value'] ?? 90);
    }

    /**
     * Stocks with quantity > 0 and no deductions since the cutoff. `last_deduct_at` (latest
     * deduction ever, NULL = never) is a correlated subquery, so only returned rows are aggregated.
     *
     * @return Builder<Stock>
     */
    public static function getReportQuery(int $minDaysInactive): Builder
    {
        $cutoff = now()->subDays($minDaysInactive)->toDateTimeString();

        $deductsForStock = fn () => Movement::query()
            ->whereColumn('inventorix_movements.stockable_id', 'inventorix_stocks.stockable_id')
            ->whereColumn('inventorix_movements.stockable_type', 'inventorix_stocks.stockable_type')
            ->whereColumn('inventorix_movements.location_id', 'inventorix_stocks.location_id')
            ->where('inventorix_movements.type', MovementType::Deduct->value);

        return Stock::query()
            ->select('inventorix_stocks.*')
            ->addSelect([
                'last_deduct_at' => $deductsForStock()->selectRaw('MAX(inventorix_movements.created_at)'),
                'brand_name' => static::brandNameSubquery(),
            ])
            // Nested eager loads through the stockable morphTo (stockable.brand) can't be serialized
            // for exports, so the brand name is selected as a subquery instead.
            ->with(['stockable', 'location'])
            ->whereHasMorph('stockable', [Product::class])
            ->where('quantity', '>', 0)
            ->whereNotExists($deductsForStock()->where('inventorix_movements.created_at', '>=', $cutoff)->toBase());
    }

    /**
     * @return Builder<Brand>
     */
    public static function brandNameSubquery(): Builder
    {
        return Brand::query()
            ->select('brands.name')
            ->join('products', 'products.brand_id', '=', 'brands.id')
            ->whereColumn('products.id', 'inventorix_stocks.stockable_id')
            ->limit(1);
    }

    public static function formatLastMovement(Stock $record): string
    {
        $date = $record->getAttribute('last_deduct_at');

        return $date ? Carbon::parse($date)->format('M d, Y') : 'Never';
    }

    public static function formatDaysInactive(Stock $record): string
    {
        $date = $record->getAttribute('last_deduct_at');

        return $date ? (string) (int) Carbon::parse($date)->diffInDays(now()) : '—';
    }

    public static function stockValue(Stock $record): float
    {
        return round((float) $record->quantity * (float) ($record->stockable?->getAttribute('cost') ?? 0), 2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => static::getReportQuery($this->getMinDaysInactive()))
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

                TextColumn::make('brand_name')
                    ->label('Brand')
                    ->placeholder('—'),

                TextColumn::make('location.name')
                    ->label('Location'),

                TextColumn::make('quantity')
                    ->label('Qty On Hand')
                    ->numeric(decimalPlaces: 2),

                TextColumn::make('last_movement')
                    ->label('Last Movement Date')
                    ->state(fn (Stock $record): string => static::formatLastMovement($record)),

                TextColumn::make('days_inactive')
                    ->label('Days Since Last Movement')
                    ->state(fn (Stock $record): string => static::formatDaysInactive($record))
                    // More days inactive = older last deduction; never deducted (NULL) is the most inactive.
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query
                        ->orderByRaw('last_deduct_at IS NULL '.($direction === 'desc' ? 'DESC' : 'ASC'))
                        ->orderBy('last_deduct_at', $direction === 'desc' ? 'asc' : 'desc')),

                TextColumn::make('stock_value')
                    ->label('Stock Value')
                    ->state(fn (Stock $record): float => static::stockValue($record))
                    ->money('USD'),
            ])
            ->filters([
                SelectFilter::make('min_days_inactive')
                    ->label('Min Days Inactive')
                    ->options([
                        '30' => '30 days',
                        '60' => '60 days',
                        '90' => '90 days',
                        '180' => '180 days',
                        '365' => '365 days',
                    ])
                    ->default('90')
                    // Applied in getReportQuery() via the table query.
                    ->query(fn (Builder $query, array $data): Builder => $query),

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

                SelectFilter::make('brand')
                    ->label('Brand')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => Brand::query()->where('name', 'like', "%{$search}%")->limit(50)->pluck('name', 'id'))
                    ->getOptionLabelUsing(fn ($value) => Brand::find($value)?->name)
                    ->query(function (Builder $query, array $data): Builder {
                        if (blank($data['value'])) {
                            return $query;
                        }

                        return $query->whereHasMorph('stockable', [Product::class],
                            fn (Builder $q) => $q->where('brand_id', $data['value'])
                        );
                    }),
            ])
            ->headerActions([
                ExportAction::make()
                    ->exporter(DeadStockReportExporter::class),
            ])
            ->defaultSort('quantity', 'desc');
    }
}
