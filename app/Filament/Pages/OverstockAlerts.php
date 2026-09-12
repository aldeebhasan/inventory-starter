<?php

namespace App\Filament\Pages;

use Aldeebhasan\Inventorix\Models\Stock;
use Aldeebhasan\Inventorix\Models\Threshold;
use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
use BackedEnum;
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

class OverstockAlerts extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.overstock-alerts';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationCircle;

    protected static string|UnitEnum|null $navigationGroup = 'Inventory Reports';

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

    /** @var Collection<string, Threshold>|null */
    protected ?Collection $thresholdCache = null;

    protected function getThresholds(): Collection
    {
        if ($this->thresholdCache !== null) {
            return $this->thresholdCache;
        }

        $this->thresholdCache = Threshold::query()
            ->where('stockable_type', Product::class)
            ->whereNotNull('max_quantity')
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

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Stock::query()
                    ->with(['stockable.brand', 'location'])
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
                    ->state(function (Stock $record): string {
                        $threshold = $this->getThresholdFor($record);

                        return $threshold?->max_quantity !== null ? number_format((float) $threshold->max_quantity, 2) : '—';
                    }),

                TextColumn::make('surplus')
                    ->label('Surplus')
                    ->badge()
                    ->color('warning')
                    ->state(function (Stock $record): string {
                        $threshold = $this->getThresholdFor($record);
                        if (! $threshold || $threshold->max_quantity === null) {
                            return '—';
                        }

                        $available = $record->quantity - $record->reserved_quantity;
                        $surplus = $available - (float) $threshold->max_quantity;

                        return $surplus > 0 ? number_format($surplus, 2) : '0.00';
                    }),
            ])
            ->filters([
                SelectFilter::make('location_id')
                    ->label('Location')
                    ->options(fn () => Location::query()->where('is_active', true)->pluck('name', 'id'))
                    ->searchable(),

                SelectFilter::make('category')
                    ->label('Category')
                    ->options(fn () => Category::query()->pluck('name', 'id'))
                    ->searchable()
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
