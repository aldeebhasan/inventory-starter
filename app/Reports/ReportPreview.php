<?php

namespace App\Reports;

use Aldeebhasan\Panorama\Exceptions\PanoramaException;
use Aldeebhasan\Panorama\Facades\Panorama;
use Aldeebhasan\Panorama\Report\Column;
use App\Models\ReportDefinition;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * What the builder canvas shows for a definition, saved or not: the first rows, their totals, the chart series
 * and the KPI numbers, with the previous period alongside when comparing.
 */
class ReportPreview
{
    public const ROW_LIMIT = 500;

    public function __construct(private readonly ReportSchema $schema) {}

    /**
     * @return array{
     *     error: string|null,
     *     range: string|null,
     *     compareRange: string|null,
     *     columns: list<array{key: string, label: string, numeric: bool, metric: bool}>,
     *     rows: list<list<string>>,
     *     totals: list<string>|null,
     *     truncated: bool,
     *     chart: array<string, mixed>|null,
     *     kpis: list<array{label: string, value: string, change: float|null}>
     * }
     */
    public function build(ReportDefinition $report, User $user): array
    {
        $definition = ReportSchema::normalize($report->definition);
        $empty = ['error' => null, 'range' => null, 'compareRange' => null, 'columns' => [], 'rows' => [], 'totals' => null, 'truncated' => false, 'chart' => null, 'kpis' => []];
        $errors = $this->schema->errors($definition);

        if ($errors !== []) {
            return ['error' => implode(' ', $errors)] + $empty;
        }

        $range = DateRange::from($definition['date_range']);
        $dated = $definition['parameters'] !== [];
        $comparing = $dated && $definition['date_range']['compare'] && $range->isBounded();

        try {
            // Unsaved edits preview too, so the cache is keyed by the definition itself, apart from the full result.
            $preview = new DefinedReport($report, 'preview-'.md5((string) json_encode($definition)), self::ROW_LIMIT + 1);
            $rows = Panorama::run($preview, $dated ? $range->params() : [], $user)->get();
            $previous = $comparing ? Panorama::run($preview, $range->previous()->params(), $user)->get() : [];
        } catch (PanoramaException|ValidationException $exception) {
            return ['error' => $exception->getMessage()] + $empty;
        }

        $truncated = count($rows) > self::ROW_LIMIT;
        $rows = array_slice($rows, 0, self::ROW_LIMIT);
        $metrics = array_column($definition['aggregates'], 'as');
        $columns = $preview->previewColumns();
        $metricColumns = array_values(array_filter($columns, fn (Column $column): bool => in_array($column->name, $metrics, true)));

        return [
            'error' => null,
            'range' => $dated ? $range->label() : null,
            'compareRange' => $comparing ? $range->previous()->label() : null,
            'columns' => array_map(fn (Column $column): array => [
                'key' => $column->name,
                'label' => $column->heading(),
                'numeric' => $column->isNumeric() || in_array($column->name, $metrics, true),
                'metric' => in_array($column->name, $metrics, true),
            ], $columns),
            'rows' => array_map(fn (array $row): array => array_map(fn (Column $column): string => $column->display($row), $columns), $rows),
            // Totals over a cut-off result would be wrong, so they are shown only for complete ones.
            'totals' => $truncated ? null : $this->totals($columns, $rows),
            'truncated' => $truncated,
            'chart' => $this->chart($definition, $columns, $metricColumns, $rows, $previous),
            'kpis' => $definition['visualization'] === 'kpi' ? $this->kpis($metricColumns, $rows, $previous) : [],
        ];
    }

    /**
     * @param  list<Column>  $columns
     * @param  list<array<string, mixed>>  $rows
     * @return list<string>|null
     */
    private function totals(array $columns, array $rows): ?array
    {
        if ($rows === [] || ! collect($columns)->contains(fn (Column $column): bool => $column->totalled())) {
            return null;
        }

        return array_map(function (Column $column, int $index) use ($rows): string {
            if (! $column->totalled()) {
                return $index === 0 ? 'Total' : '';
            }

            return $column->display([$column->name => array_sum(array_map(fn (array $row): float => (float) ($row[$column->name] ?? 0), $rows))]);
        }, $columns, array_keys($columns));
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  list<Column>  $columns
     * @param  list<Column>  $metricColumns
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $previous
     * @return array<string, mixed>|null
     */
    private function chart(array $definition, array $columns, array $metricColumns, array $rows, array $previous): ?array
    {
        $type = $definition['visualization'];

        if (in_array($type, ['table', 'kpi'], true) || $metricColumns === [] || $rows === []) {
            return null;
        }

        $dimensions = array_values(array_filter($columns, fn (Column $column): bool => ! in_array($column, $metricColumns, true)));
        $labels = array_map(
            fn (array $row): string => $dimensions === [] ? 'Total' : implode(' · ', array_map(fn (Column $column): string => $column->display($row), $dimensions)),
            $rows,
        );
        $series = in_array($type, ['pie', 'donut'], true) ? [$metricColumns[0]] : $metricColumns;

        $datasets = array_map(fn (Column $column): array => [
            'label' => $column->heading(),
            'data' => array_map(fn (array $row): float => (float) ($row[$column->name] ?? 0), $rows),
        ], $series);

        // The previous period is drawn for the first metric, point by point.
        if ($previous !== [] && ! in_array($type, ['pie', 'donut'], true)) {
            $datasets[] = [
                'label' => $series[0]->heading().' (previous period)',
                'data' => array_map(fn (int $index): float => (float) ($previous[$index][$series[0]->name] ?? 0), array_keys($rows)),
                'previous' => true,
            ];
        }

        return ['type' => $type, 'labels' => $labels, 'datasets' => $datasets];
    }

    /**
     * @param  list<Column>  $metricColumns
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $previous
     * @return list<array{label: string, value: string, change: float|null}>
     */
    private function kpis(array $metricColumns, array $rows, array $previous): array
    {
        return array_map(function (Column $column) use ($rows, $previous): array {
            $current = (float) ($rows[0][$column->name] ?? 0);
            $before = isset($previous[0]) ? (float) ($previous[0][$column->name] ?? 0) : null;

            return [
                'label' => $column->heading(),
                'value' => $column->display([$column->name => $current]),
                'change' => $before === null || $before == 0.0 ? null : round(($current - $before) / abs($before) * 100, 1),
            ];
        }, $metricColumns);
    }
}
