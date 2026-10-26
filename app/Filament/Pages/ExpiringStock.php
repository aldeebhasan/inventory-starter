<?php

namespace App\Filament\Pages;

use Aldeebhasan\Inventorix\Enums\MovementType;
use Aldeebhasan\Inventorix\Models\Movement;
use App\Filament\Exports\ExpiringStockExporter;
use App\Models\Location;
use App\Models\Product;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\ExportAction;
use Filament\Forms\Components\Toggle;
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
use UnitEnum;

class ExpiringStock extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.expiring-stock';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?string $navigationParentItem = 'Reports';

    protected static ?int $navigationSort = 16;

    protected static ?string $navigationLabel = 'Expiring Stock';

    public function getTitle(): string|Htmlable
    {
        return 'Expiring Stock Report';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Lot-level stock nearing or past its expiry date — prioritize usage or disposal before spoilage.';
    }

    protected function getDaysThreshold(): int
    {
        return (int) (($this->tableFilters['days_until_expiry']['value'] ?? null) ?: 30);
    }

    public static function qtyRemaining(Movement $record): float
    {
        return (float) $record->quantity - (float) ($record->consumed_quantity ?? 0);
    }

    public static function daysUntilExpiry(Movement $record): int
    {
        return (int) now()->startOfDay()->diffInDays(Carbon::parse($record->expires_at)->startOfDay(), false);
    }

    public static function status(Movement $record, int $daysThreshold): string
    {
        $days = static::daysUntilExpiry($record);

        return match (true) {
            $days < 0 => 'Expired',
            $days <= $daysThreshold => 'Expiring Soon',
            default => 'OK',
        };
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Movement::query()
                    ->with(['stockable', 'location'])
                    ->whereHasMorph('stockable', [Product::class])
                    ->where('type', MovementType::Add)
                    ->whereNotNull('expires_at')
                    ->whereRaw('quantity - COALESCE(consumed_quantity, 0) > 0')
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

                TextColumn::make('location.name')
                    ->label('Location'),

                TextColumn::make('lot_reference')
                    ->label('Lot Reference')
                    ->placeholder('—'),

                TextColumn::make('qty_remaining')
                    ->label('Qty Remaining')
                    ->state(fn (Movement $record): string => number_format(static::qtyRemaining($record), 3)),

                TextColumn::make('cost_per_unit')
                    ->label('Cost/Unit')
                    ->numeric(decimalPlaces: 3)
                    ->placeholder('—'),

                TextColumn::make('expires_at')
                    ->label('Expires At')
                    ->date()
                    ->sortable(),

                TextColumn::make('days_until_expiry')
                    ->label('Days Until Expiry')
                    ->state(fn (Movement $record): string => (string) static::daysUntilExpiry($record)),

                TextColumn::make('expiry_status')
                    ->label('Status')
                    ->badge()
                    ->state(fn (Movement $record): string => static::status($record, $this->getDaysThreshold()))
                    ->color(fn (string $state): string => match ($state) {
                        'Expired' => 'danger',
                        'Expiring Soon' => 'warning',
                        default => 'success',
                    }),
            ])
            ->filters([
                SelectFilter::make('days_until_expiry')
                    ->label('Days Until Expiry')
                    ->options([
                        '7' => '7 days',
                        '14' => '14 days',
                        '30' => '30 days',
                        '60' => '60 days',
                        '90' => '90 days',
                    ])
                    ->default('30')
                    ->query(function (Builder $query, array $data): Builder {
                        if (blank($data['value'])) {
                            return $query;
                        }

                        return $query->where('expires_at', '<=', now()->addDays((int) $data['value']));
                    }),

                Filter::make('show_expired')
                    ->label('Show Expired')
                    ->form([
                        Toggle::make('show_expired')->label('Include expired lots')->default(false),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        if (! ($data['show_expired'] ?? false)) {
                            // Lots expiring today are not expired yet (days until expiry = 0).
                            return $query->where('expires_at', '>=', today());
                        }

                        return $query;
                    }),

                SelectFilter::make('location_id')
                    ->label('Location')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => Location::query()->where('is_active', true)->where('name', 'like', "%{$search}%")->limit(50)->pluck('name', 'id'))
                    ->getOptionLabelUsing(fn ($value) => Location::find($value)?->name)
                    ->query(function (Builder $query, array $data): Builder {
                        if (blank($data['value'])) {
                            return $query;
                        }

                        return $query->where('location_id', $data['value']);
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

                        return $query->where('stockable_id', $data['value'])
                            ->where('stockable_type', Product::class);
                    }),
            ])
            ->headerActions([
                ExportAction::make()
                    ->exporter(ExpiringStockExporter::class)
                    ->options(fn (): array => ['days_threshold' => $this->getDaysThreshold()]),
            ])
            ->defaultSort('expires_at', 'asc');
    }
}
