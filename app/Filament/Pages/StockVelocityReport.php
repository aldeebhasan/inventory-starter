<?php

namespace App\Filament\Pages;

use Aldeebhasan\Inventorix\Enums\MovementType;
use Aldeebhasan\Inventorix\Models\Movement;
use Aldeebhasan\Inventorix\Models\Stock;
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
use Illuminate\Support\Facades\DB;
use UnitEnum;

class StockVelocityReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.stock-velocity-report';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    protected static string|UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Stock Velocity';

    public function getTitle(): string|Htmlable
    {
        return 'Stock Velocity & Runway';
    }

    /** @var Collection<string, object>|null */
    protected ?Collection $velocityCache = null;

    /** @var Collection<string, object>|null */
    protected ?Collection $peakCache = null;

    protected function getPeriod(): int
    {
        $filter = $this->tableFilters['period']['value'] ?? null;

        return $filter ? (int) $filter : 30;
    }

    protected function getVelocities(): Collection
    {
        if ($this->velocityCache !== null) {
            return $this->velocityCache;
        }

        $this->velocityCache = Movement::query()
            ->where('type', MovementType::Deduct)
            ->where('created_at', '>=', now()->subDays($this->getPeriod()))
            ->selectRaw('stockable_id, location_id, SUM(quantity) as total_deducted, COUNT(DISTINCT DATE(created_at)) as active_days')
            ->groupBy('stockable_id', 'location_id')
            ->get()
            ->keyBy(fn ($m) => $m->stockable_id.'-'.$m->location_id);

        return $this->velocityCache;
    }

    protected function getPeaks(): Collection
    {
        if ($this->peakCache !== null) {
            return $this->peakCache;
        }

        $this->peakCache = Movement::query()
            ->where('type', MovementType::Deduct)
            ->where('created_at', '>=', now()->subDays($this->getPeriod()))
            ->selectRaw('stockable_id, location_id, DATE(created_at) as demand_date, SUM(quantity) as daily_total')
            ->groupBy('stockable_id', 'location_id', DB::raw('DATE(created_at)'))
            ->orderByDesc('daily_total')
            ->get()
            ->unique(fn ($m) => $m->stockable_id.'-'.$m->location_id);

        return $this->peakCache;
    }

    protected function getVelocityFor(Stock $record): ?object
    {
        return $this->getVelocities()->get($record->stockable_id.'-'.$record->location_id);
    }

    protected function getPeakFor(Stock $record): ?object
    {
        return $this->getPeaks()->first(fn ($m) => $m->stockable_id == $record->stockable_id && $m->location_id == $record->location_id);
    }

    protected function getDaysOfStock(Stock $record): ?float
    {
        $velocity = $this->getVelocityFor($record);
        if (! $velocity || $velocity->total_deducted <= 0) {
            return null;
        }

        $available = $record->quantity - $record->reserved_quantity;
        $avgDaily = $velocity->total_deducted / $this->getPeriod();

        return $avgDaily > 0 ? $available / $avgDaily : null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Stock::query()
                    ->with(['stockable.brand', 'location'])
                    ->whereHasMorph('stockable', [Product::class])
                    ->where('quantity', '>', 0)
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

                TextColumn::make('quantity')
                    ->label('On Hand')
                    ->numeric(decimalPlaces: 2)
                    ->sortable(),

                TextColumn::make('available')
                    ->label('Available')
                    ->numeric(decimalPlaces: 2)
                    ->state(fn (Stock $record): float => $record->quantity - $record->reserved_quantity),

                TextColumn::make('avg_daily_consumption')
                    ->label('Avg Daily Consumption')
                    ->state(function (Stock $record): string {
                        $velocity = $this->getVelocityFor($record);
                        if (! $velocity || $velocity->total_deducted <= 0) {
                            return '0.00';
                        }

                        return number_format($velocity->total_deducted / $this->getPeriod(), 2);
                    }),

                TextColumn::make('days_of_stock')
                    ->label('Days of Stock')
                    ->state(function (Stock $record): string {
                        $days = $this->getDaysOfStock($record);

                        return $days === null ? '∞' : (string) (int) $days;
                    }),

                TextColumn::make('peak_demand_day')
                    ->label('Peak Demand Day')
                    ->state(function (Stock $record): string {
                        $peak = $this->getPeakFor($record);

                        return $peak?->demand_date ?? '—';
                    }),

                TextColumn::make('velocity_status')
                    ->label('Status')
                    ->badge()
                    ->state(function (Stock $record): string {
                        $days = $this->getDaysOfStock($record);

                        if ($days === null) {
                            return 'Healthy';
                        }

                        if ($days < 7) {
                            return 'Critical';
                        }

                        if ($days < 14) {
                            return 'Warning';
                        }

                        return 'Healthy';
                    })
                    ->color(function (Stock $record): string {
                        $days = $this->getDaysOfStock($record);

                        if ($days === null) {
                            return 'success';
                        }

                        if ($days < 7) {
                            return 'danger';
                        }

                        if ($days < 14) {
                            return 'warning';
                        }

                        return 'success';
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

                SelectFilter::make('period')
                    ->label('Period')
                    ->options([
                        '7' => '7 days',
                        '14' => '14 days',
                        '30' => '30 days',
                        '60' => '60 days',
                        '90' => '90 days',
                    ])
                    ->default('30')
                    ->query(fn (Builder $query, array $data): Builder => $query),

                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'critical' => 'Critical (<7d)',
                        'warning' => 'Warning (<14d)',
                        'healthy' => 'Healthy',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query),
            ])
            ->defaultSort('quantity', 'asc');
    }
}
