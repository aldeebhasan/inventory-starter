<?php

namespace App\Filament\Exports;

use App\Filament\Pages\AbcAnalysis;
use App\Models\Product;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;

/**
 * Exports the ABC Analysis. Ranking needs every product's consumption, so the export job computes it
 * once per job (one aggregate query, cached on the exporter) from the page's filters passed as options.
 */
class AbcAnalysisExporter extends Exporter
{
    protected static ?string $model = Product::class;

    /** @var Collection<int, array{consumption_qty: float, consumption_value: float, avg_cost: float, cumulative_pct: float|int, abc_class: string}>|null */
    protected ?Collection $abcData = null;

    /** @var array<int, int>|null product id => 1-based rank */
    protected ?array $ranks = null;

    /**
     * @return array{consumption_qty: float, consumption_value: float, avg_cost: float, cumulative_pct: float|int, abc_class: string}|null
     */
    public function abcFor(Product $record): ?array
    {
        $this->abcData ??= AbcAnalysis::computeAbc($this->getOptions());

        return $this->abcData->get($record->getKey());
    }

    public function rankFor(Product $record): ?int
    {
        $this->abcData ??= AbcAnalysis::computeAbc($this->getOptions());
        $this->ranks ??= array_flip($this->abcData->keys()->all());

        $index = $this->ranks[$record->getKey()] ?? null;

        return $index === null ? null : $index + 1;
    }

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('rank')
                ->label('#')
                ->state(fn (Product $record, self $exporter): ?int => $exporter->rankFor($record)),
            ExportColumn::make('name')->label('Product'),
            ExportColumn::make('brand.name')->label('Brand'),
            ExportColumn::make('consumption_qty')
                ->label('Consumption Qty')
                ->state(fn (Product $record, self $exporter): float => $exporter->abcFor($record)['consumption_qty'] ?? 0),
            ExportColumn::make('avg_cost')
                ->label('Avg Cost/Unit')
                ->state(fn (Product $record, self $exporter): float => $exporter->abcFor($record)['avg_cost'] ?? 0),
            ExportColumn::make('consumption_value')
                ->label('Consumption Value')
                ->state(fn (Product $record, self $exporter): float => round($exporter->abcFor($record)['consumption_value'] ?? 0, 2)),
            ExportColumn::make('cumulative_pct')
                ->label('Cumulative %')
                ->state(fn (Product $record, self $exporter): float => round($exporter->abcFor($record)['cumulative_pct'] ?? 0, 1)),
            ExportColumn::make('abc_class')
                ->label('ABC Class')
                ->state(fn (Product $record, self $exporter): string => $exporter->abcFor($record)['abc_class'] ?? 'C'),
        ];
    }

    public static function modifyQuery(Builder $query): Builder
    {
        return $query->with('brand');
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your ABC analysis export has completed and '.Number::format($export->successful_rows).' '.str('row')->plural($export->successful_rows).' exported.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.Number::format($failedRowsCount).' '.str('row')->plural($failedRowsCount).' failed to export.';
        }

        return $body;
    }
}
