<?php

namespace App\Reports;

use Aldeebhasan\Panorama\Dataset\Datasets;
use Aldeebhasan\Panorama\Schema\FieldType;
use Illuminate\Support\Str;

/**
 * The builder's edits, applied to a definition array. The stored JSON stays the one DefinedReport reads:
 * with metrics the dimensions are its `group_by`, without them its plain `columns`.
 */
class DefinitionEditor
{
    public function __construct(private readonly ReportSchema $schema, private readonly Datasets $datasets) {}

    /**
     * @return array<string, mixed>
     */
    public static function blank(string $base): array
    {
        return ReportSchema::normalize(['base' => $base, 'visualization' => 'table']);
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return list<string>
     */
    public function dimensions(array $definition): array
    {
        if ($definition['aggregates'] !== []) {
            return $definition['group_by'];
        }

        return array_values(array_filter(array_column($definition['columns'], 'field'), fn (string $field): bool => str_contains($field, '.')));
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    public function addDimension(array $definition, string $field, ?string $relation = null): array
    {
        [$definition, $field] = $this->reach($definition, $field, $relation);
        $dimensions = $this->dimensions($definition);

        if (! in_array($field, $dimensions, true)) {
            $dimensions[] = $field;
        }

        return $this->rebuild($definition, $dimensions, $definition['aggregates']);
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    public function removeDimension(array $definition, string $field): array
    {
        $definition = $this->rebuild($definition, array_values(array_diff($this->dimensions($definition), [$field])), $definition['aggregates']);
        $definition['order_by'] = array_values(array_filter($definition['order_by'], fn (array $order): bool => $order['column'] !== $field));

        return $this->pruneJoins($definition);
    }

    /**
     * A date dimension regrouped by day, week, month or year — or by its exact value when `$bucket` is null.
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    public function setBucket(array $definition, string $field, ?string $bucket): array
    {
        $base = self::unbucketed($field);
        $replacement = $bucket === null ? $base : "{$base}__{$bucket}";
        $dimensions = array_map(fn (string $dimension): string => $dimension === $field ? $replacement : $dimension, $this->dimensions($definition));
        $definition = $this->rebuild($definition, $dimensions, $definition['aggregates']);

        foreach ($definition['order_by'] as &$order) {
            $order['column'] = $order['column'] === $field ? $replacement : $order['column'];
        }

        return $definition;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    public function addMetric(array $definition, string $fn, ?string $field = null, ?string $relation = null): array
    {
        if ($field !== null) {
            [$definition, $field] = $this->reach($definition, $field, $relation);
        }

        $aggregates = $definition['aggregates'];
        $aggregates[] = ['fn' => $fn, 'field' => $field, 'as' => $this->metricName($definition, $fn, $field)];

        return $this->rebuild($definition, $this->dimensions($definition), $aggregates);
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    public function removeMetric(array $definition, string $as): array
    {
        $dimensions = $this->dimensions($definition);
        $aggregates = array_values(array_filter($definition['aggregates'], fn (array $aggregate): bool => $aggregate['as'] !== $as));
        $definition = $this->rebuild($definition, $dimensions, $aggregates);
        $definition['having'] = array_values(array_filter($definition['having'], fn (array $having): bool => $having['column'] !== $as));
        $definition['order_by'] = array_values(array_filter($definition['order_by'], fn (array $order): bool => $order['column'] !== $as));

        return $this->pruneJoins($definition);
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    public function setMetricFunction(array $definition, string $as, string $fn): array
    {
        foreach ($definition['aggregates'] as &$aggregate) {
            // Counting rows has no field, so no other function applies to it.
            if ($aggregate['as'] === $as && in_array($fn, ReportSchema::AGGREGATES, true) && $aggregate['field'] !== null) {
                $before = $aggregate;
                $aggregate['fn'] = $fn;
                $metric = $aggregate;
            }
        }

        unset($aggregate);

        foreach ($definition['columns'] as &$column) {
            if (isset($metric, $before) && $column['field'] === $as) {
                // A label that only named the old function is dropped, so the column is named after the new one.
                if (($column['label'] ?? null) === $this->metricLabel($definition, $before)) {
                    $column['label'] = null;
                }

                $column['format'] = $this->metricFormat($definition, $metric);
                $column['total'] = in_array($fn, ['sum', 'count', 'countDistinct'], true);
            }
        }

        return $definition;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    public function updateColumn(array $definition, string $field, string $label, string $format): array
    {
        foreach ($definition['columns'] as &$column) {
            if ($column['field'] === $field) {
                // An emptied label goes back to the one named after the field or metric.
                $column['label'] = trim($label) === '' ? null : trim($label);
                $column['format'] = in_array($format, ReportSchema::FORMATS, true) ? $format : $column['format'];
            }
        }

        return $definition;
    }

    /**
     * A column's label: the one given, or one that says what it is — "Average quantity", "Orders created at (month)".
     *
     * @param  array<string, mixed>  $definition
     * @param  array{field: string, label?: string|null}  $column
     */
    public function columnLabel(array $definition, array $column): string
    {
        if (filled($column['label'] ?? null)) {
            return $column['label'];
        }

        $metric = collect($definition['aggregates'] ?? [])->firstWhere('as', $column['field']);

        return match (true) {
            $metric !== null => $this->metricLabel($definition, $metric),
            str_contains($column['field'], '.') => $this->dimensionLabel($column['field']),
            default => $column['field'],
        };
    }

    /**
     * The saved date range, and the run-time parameters it needs: none for "All time" or a report without dates.
     *
     * @param  array<string, mixed>  $definition
     * @param  array{preset: string, from?: string|null, to?: string|null, compare?: bool}  $range
     * @return array<string, mixed>
     */
    public function setDateRange(array $definition, array $range): array
    {
        $definition['date_range'] = ReportSchema::normalize(['date_range' => $range])['date_range'];
        $dated = isset($this->schema->parameterOptions($definition)['from']);
        $definition['parameters'] = $dated && DateRange::from($definition['date_range'])->isBounded() ? ['from', 'to'] : [];

        return $definition;
    }

    /**
     * A filter on a field, or — on a metric of a grouped report — a condition on its total (`having`).
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    public function addFilter(array $definition, string $field, string $operator, mixed $value): array
    {
        if (in_array($field, array_column($definition['aggregates'], 'as'), true)) {
            $definition['having'][] = ['column' => $field, 'operator' => $operator, 'value' => $value];
        } else {
            $definition['filters'][] = ['field' => $field, 'operator' => $operator, 'value' => $value];
        }

        return $definition;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    public function removeFilter(array $definition, string $list, int $index): array
    {
        if (in_array($list, ['filters', 'having'], true)) {
            unset($definition[$list][$index]);
            $definition[$list] = array_values($definition[$list]);
        }

        return $this->pruneJoins($definition);
    }

    /**
     * Sorts by one column; sorting by it again flips the direction.
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    public function sortBy(array $definition, string $column): array
    {
        $current = $definition['order_by'][0] ?? null;
        $direction = $current !== null && $current['column'] === $column && ($current['direction'] ?? 'asc') === 'asc' ? 'desc' : 'asc';
        $definition['order_by'] = [['column' => $column, 'direction' => $direction]];

        return $definition;
    }

    /**
     * Groups the base dataset's own fields first, then each dataset in the report, then those one relation away.
     *
     * @param  array<string, mixed>  $definition
     * @return list<array{key: string, label: string, alias: string|null, relation: string|null, fields: list<array{name: string, label: string, type: string, numeric: bool, date: bool}>}>
     */
    public function fieldGroups(array $definition): array
    {
        $groups = [];

        foreach ($this->schema->scope($definition) as $alias => $dataset) {
            $groups[] = ['key' => "alias:{$alias}", 'label' => Str::headline($alias), 'alias' => $alias, 'relation' => null, 'fields' => $this->fieldsOf($dataset)];
        }

        $hidden = $this->followedRelations($definition);

        foreach ($this->schema->relationOptions($definition, count($definition['joins'])) as $relation => $label) {
            if (in_array($relation, $hidden, true)) {
                continue;
            }

            $dataset = ReportSchema::joinedDataset($relation);
            [$local] = explode('>', $relation);

            $groups[] = [
                'key' => "relation:{$relation}",
                'label' => Str::headline($dataset).' · via '.$local,
                'alias' => null,
                'relation' => $relation,
                'fields' => $this->fieldsOf($dataset),
            ];
        }

        return $groups;
    }

    /**
     * Relations the field list no longer offers: each join already made (its fields are listed under its alias),
     * and the way back along it (customers → sale orders → customers would only loop to the rows it came from).
     *
     * @param  array<string, mixed>  $definition
     * @return list<string>
     */
    private function followedRelations(array $definition): array
    {
        $scope = $this->schema->scope($definition);
        $relations = [];

        foreach ($definition['joins'] as $join) {
            [$local, $target] = explode('>', $join['relation']);
            [$from, $localField] = explode('.', $local);
            [, $targetField] = explode('.', $target);

            $relations[] = $join['relation'];

            if (isset($scope[$from])) {
                $relations[] = "{$join['alias']}.{$targetField}>{$scope[$from]}.{$localField}";
            }
        }

        return $relations;
    }

    /** `orders.created_at__month` → `orders.created_at`. */
    public static function unbucketed(string $field): string
    {
        return (string) preg_replace('/__(day|week|month|year)$/', '', $field);
    }

    public static function bucketOf(string $field): ?string
    {
        return preg_match('/__(day|week|month|year)$/', $field, $match) === 1 ? $match[1] : null;
    }

    /**
     * The field, reached through a new left join when it comes from a related dataset.
     *
     * @param  array<string, mixed>  $definition
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function reach(array $definition, string $field, ?string $relation): array
    {
        if ($relation === null) {
            return [$definition, $field];
        }

        $dataset = ReportSchema::joinedDataset($relation);
        $taken = array_keys($this->schema->scope($definition));
        $alias = $dataset;

        for ($n = 2; in_array($alias, $taken, true); $n++) {
            $alias = "{$dataset}_{$n}";
        }

        $definition['joins'][] = ['relation' => $relation, 'alias' => $alias, 'type' => 'left'];

        return [$definition, "{$alias}.{$field}"];
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  list<string>  $dimensions
     * @param  list<array{fn: string, field: string|null, as: string}>  $aggregates
     * @return array<string, mixed>
     */
    private function rebuild(array $definition, array $dimensions, array $aggregates): array
    {
        $existing = collect($definition['columns'])->keyBy('field');
        $definition['aggregates'] = $aggregates;
        $definition['group_by'] = $aggregates === [] ? [] : $dimensions;

        $columns = array_map(fn (string $field): array => $existing[$field] ?? [
            'field' => $field,
            'label' => null,
            'format' => $this->dimensionFormat($definition, $field),
            'total' => false,
        ], $dimensions);

        foreach ($aggregates as $aggregate) {
            $columns[] = $existing[$aggregate['as']] ?? [
                'field' => $aggregate['as'],
                'label' => null,
                'format' => $this->metricFormat($definition, $aggregate),
                'total' => in_array($aggregate['fn'], ['sum', 'count', 'countDistinct'], true),
            ];
        }

        $definition['columns'] = $columns;

        // A chart needs a metric; without one only the table can show the rows.
        if ($aggregates === [] && ($definition['visualization'] ?? 'table') !== 'table') {
            $definition['visualization'] = 'table';
        }

        return $definition;
    }

    /**
     * Drops joins nothing refers to any more, including through a later join.
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function pruneJoins(array $definition): array
    {
        do {
            $used = $this->referencedAliases($definition);
            $before = count($definition['joins']);
            $definition['joins'] = array_values(array_filter($definition['joins'], fn (array $join): bool => in_array($join['alias'], $used, true)));
        } while (count($definition['joins']) !== $before);

        return $definition;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return list<string>
     */
    private function referencedAliases(array $definition): array
    {
        $fields = [
            ...array_column($definition['columns'], 'field'),
            ...$definition['group_by'],
            ...array_filter(array_column($definition['aggregates'], 'field')),
            ...array_column($definition['filters'], 'field'),
            ...array_column($definition['order_by'], 'column'),
            ...array_map(fn (array $join): string => explode('>', $join['relation'])[0], $definition['joins']),
        ];

        return array_values(array_unique(array_map(fn (string $field): string => explode('.', $field)[0], array_filter($fields, fn (string $field): bool => str_contains($field, '.')))));
    }

    /**
     * @return list<array{name: string, label: string, type: string, numeric: bool, date: bool}>
     */
    private function fieldsOf(string $dataset): array
    {
        $fields = [];

        foreach ($this->datasets->get($dataset)->schema()->fields() as $name => $field) {
            if (self::bucketOf($name) !== null) {
                continue;
            }

            $fields[] = [
                'name' => $name,
                'label' => Str::headline($name),
                'type' => $field->type->value,
                // Keys and ids are numbers you group by, not numbers you add up.
                'numeric' => $field->type->isNumeric() && $name !== 'id' && ! str_ends_with($name, '_id'),
                'date' => in_array($field->type, [FieldType::Date, FieldType::Datetime], true),
            ];
        }

        return $fields;
    }

    private function dimensionLabel(string $field): string
    {
        [$alias, $name] = explode('.', self::unbucketed($field), 2);
        $bucket = self::bucketOf($field);

        return Str::headline($alias).' '.Str::lower(Str::headline($name)).($bucket === null ? '' : " ({$bucket})");
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function dimensionFormat(array $definition, string $field): string
    {
        if (self::bucketOf($field) !== null) {
            return 'text';
        }

        return match ($this->schema->field($definition, $field)?->type) {
            FieldType::Decimal => 'number',
            FieldType::Date => 'date',
            FieldType::Datetime => 'datetime',
            default => 'text',
        };
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array{fn: string, field: string|null}  $metric
     */
    private function metricLabel(array $definition, array $metric): string
    {
        if ($metric['field'] === null) {
            return 'Count of rows';
        }

        $fn = ['sum' => 'Total', 'avg' => 'Average', 'min' => 'Lowest', 'max' => 'Highest', 'count' => 'Count of', 'countDistinct' => 'Distinct'][$metric['fn']] ?? $metric['fn'];
        [$alias, $name] = explode('.', $metric['field'], 2);

        return "{$fn} ".Str::lower(Str::headline($name)).($alias === $definition['base'] ? '' : ' ('.Str::headline($alias).')');
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array{fn: string, field: string|null}  $metric
     */
    private function metricFormat(array $definition, array $metric): string
    {
        if (in_array($metric['fn'], ['count', 'countDistinct'], true)) {
            return 'integer';
        }

        $name = explode('.', (string) $metric['field'], 2)[1] ?? '';

        return preg_match('/price|cost|amount|total/', $name) === 1 ? 'money' : 'number';
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function metricName(array $definition, string $fn, ?string $field): string
    {
        $name = Str::snake($fn).'_'.($field === null ? 'rows' : str_replace('.', '_', $field));
        $taken = array_column($definition['aggregates'], 'as');
        $unique = $name;

        for ($n = 2; in_array($unique, $taken, true); $n++) {
            $unique = "{$name}_{$n}";
        }

        return $unique;
    }
}
