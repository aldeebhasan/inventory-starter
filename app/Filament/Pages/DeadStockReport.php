<?php

namespace App\Filament\Pages;

use Aldeebhasan\Inventorix\Enums\MovementType;
use Aldeebhasan\Inventorix\Models\Movement;
use Aldeebhasan\Inventorix\Models\Stock;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
use BackedEnum;
use Carbon\Carbon;
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

class DeadStockReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.dead-stock-report';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBoxXMark;

    protected static string|UnitEnum|null $navigationGroup = 'Inventory Reports';

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

    /** @var Collection<string, Movement>|null */
    protected ?Collection $lastMovementCache = null;

    protected function getMinDaysInactive(): int
    {
        return (int) ($this->tableFilters['min_days_inactive']['value'] ?? 90);
    }

    protected function getLastMovementDates(): Collection
    {
        if ($this->lastMovementCache !== null) {
            return $this->lastMovementCache;
        }

        $this->lastMovementCache = Movement::query()
            ->whereHasMorph('stockable', [Product::class])
            ->where('type', MovementType::Deduct)
            ->selectRaw('stockable_id, location_id, MAX(created_at) as last_deduct_at')
            ->groupBy('stockable_id', 'location_id')
            ->get()
            ->keyBy(fn ($row) => $row->stockable_id.'-'.$row->location_id);

        return $this->lastMovementCache;
    }

    protected function getLastMovementFor(Stock $record): ?string
    {
        return $this->getLastMovementDates()
            ->get($record->stockable_id.'-'.$record->location_id)
            ?->getAttribute('last_deduct_at');
    }

    public function table(Table $table): Table
    {
        $cutoff = now()->subDays($this->getMinDaysInactive())->toDateTimeString();

        return $table
            ->query(
                Stock::query()
                    ->with(['stockable.brand', 'location'])
                    ->whereHasMorph('stockable', [Product::class])
                    ->where('quantity', '>', 0)
                    ->whereNotExists(function ($sub) use ($cutoff) {
                        $sub->from('inventorix_movements')
                            ->whereColumn('inventorix_movements.stockable_id', 'inventorix_stocks.stockable_id')
                            ->whereColumn('inventorix_movements.stockable_type', 'inventorix_stocks.stockable_type')
                            ->whereColumn('inventorix_movements.location_id', 'inventorix_stocks.location_id')
                            ->where('inventorix_movements.type', MovementType::Deduct->value)
                            ->where('inventorix_movements.created_at', '>=', $cutoff);
                    })
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
                    ->label('Qty On Hand')
                    ->numeric(decimalPlaces: 2),

                TextColumn::make('last_movement')
                    ->label('Last Movement Date')
                    ->state(function (Stock $record): string {
                        $date = $this->getLastMovementFor($record);

                        return $date ? Carbon::parse($date)->format('M d, Y') : 'Never';
                    }),

                TextColumn::make('days_inactive')
                    ->label('Days Since Last Movement')
                    ->state(function (Stock $record): string {
                        $date = $this->getLastMovementFor($record);
                        if (! $date) {
                            return '—';
                        }

                        return (string) (int) now()->diffInDays(Carbon::parse($date));
                    })
                    ->sortable(query: fn (Builder $query, string $direction) => $query),

                TextColumn::make('stock_value')
                    ->label('Stock Value')
                    ->state(function (Stock $record): string {
                        $cost = (float) ($record->stockable?->getAttribute('cost') ?? 0);

                        return number_format($record->quantity * $cost, 2);
                    })
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
            ->defaultSort('quantity', 'desc');
    }
}
