<?php

namespace App\Reports;

use Aldeebhasan\Panorama\Dataset\Datasets;
use Aldeebhasan\Panorama\Schema\Field;
use Aldeebhasan\Panorama\Schema\FieldType;
use Illuminate\Support\Str;

/**
 * What a report definition can refer to: the datasets in its scope, their fields and relations, and the checks
 * that keep a saved definition runnable. Feeds both the builder form and DefinedReport.
 */
class ReportSchema
{
    public const OPERATORS = ['=', '!=', '>', '>=', '<', '<=', 'like', 'not like', 'in', 'not in', 'between', 'null', 'not null'];

    public const AGGREGATES = ['sum', 'avg', 'min', 'max', 'count', 'countDistinct'];

    public const FORMATS = ['text', 'integer', 'number', 'money', 'percent', 'date', 'datetime'];

    public const VISUALIZATIONS = ['table', 'bar', 'horizontal_bar', 'line', 'area', 'pie', 'donut', 'kpi'];

    public function __construct(private readonly Datasets $datasets) {}

    /**
     * @return array<string, string>
     */
    public function datasetOptions(): array
    {
        $names = $this->datasets->names();
        sort($names);

        return collect($names)->mapWithKeys(fn (string $name): array => [$name => Str::headline($name)])->all();
    }

    /**
     * Aliases in scope, in order, mapped to their dataset. With `$joins` set, only that many joins are counted.
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, string>
     */
    public function scope(array $definition, ?int $joins = null): array
    {
        $base = $definition['base'] ?? null;

        if (! is_string($base) || ! $this->datasets->has($base)) {
            return [];
        }

        $scope = [$base => $base];

        foreach (array_slice(array_values($definition['joins'] ?? []), 0, $joins) as $join) {
            $dataset = self::joinedDataset($join['relation'] ?? null);

            if ($dataset !== null && $this->datasets->has($dataset) && filled($join['alias'] ?? null)) {
                $scope[$join['alias']] = $dataset;
            }
        }

        return $scope;
    }

    /**
     * Relations a join at the given position may follow, both directions. Values are `alias.field>dataset.field`.
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, string>
     */
    public function relationOptions(array $definition, int $position): array
    {
        $options = [];

        foreach ($this->scope($definition, $position) as $alias => $dataset) {
            foreach ($this->datasets->get($dataset)->schema()->relations() as $field => $relation) {
                $options["{$alias}.{$field}>{$relation->dataset}.{$relation->field}"] = "{$alias}.{$field} → {$relation->dataset}";
            }

            foreach ($this->datasets->all() as $name => $other) {
                foreach ($other->schema()->relations() as $field => $relation) {
                    if ($relation->dataset === $dataset) {
                        $options["{$alias}.{$relation->field}>{$name}.{$field}"] = "{$alias} ← {$name}.{$field}";
                    }
                }
            }
        }

        return $options;
    }

    /**
     * Every `alias.field` in scope.
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, string>
     */
    public function fieldOptions(array $definition, ?callable $only = null): array
    {
        $options = [];

        foreach ($this->scope($definition) as $alias => $dataset) {
            foreach ($this->datasets->get($dataset)->schema()->fields() as $name => $field) {
                if ($only === null || $only($field)) {
                    $options["{$alias}.{$name}"] = "{$alias}.{$name}";
                }
            }
        }

        return $options;
    }

    /**
     * Columns a report outputs: grouped, its group fields and aggregate aliases; aggregated only, the aggregates;
     * otherwise every field.
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, string>
     */
    public function outputOptions(array $definition): array
    {
        $aggregates = array_filter($definition['aggregates'] ?? [], fn (array $aggregate): bool => filled($aggregate['as'] ?? null));

        if (! self::isGrouped($definition)) {
            // Aggregates without a group come out as one row of totals; plain rows show every field.
            return $aggregates === []
                ? $this->fieldOptions($definition)
                : collect($aggregates)->pluck('as', 'as')->all();
        }

        $options = collect($definition['group_by'] ?? [])->filter()->mapWithKeys(fn (string $field): array => [$field => $field]);

        foreach ($definition['aggregates'] ?? [] as $aggregate) {
            if (filled($aggregate['as'] ?? null)) {
                $options[$aggregate['as']] = $aggregate['as'];
            }
        }

        return $options->all();
    }

    /**
     * The run-time parameters the datasets in scope accept, e.g. `from` / `to`.
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, string>
     */
    public function parameterOptions(array $definition): array
    {
        $options = [];

        foreach (array_unique($this->scope($definition)) as $dataset) {
            foreach (array_keys($this->datasets->get($dataset)->parameters()) as $parameter) {
                $options[$parameter] = Str::headline($parameter);
            }
        }

        return $options;
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    public function field(array $definition, string $column): ?Field
    {
        [$alias, $name] = array_pad(explode('.', $column, 2), 2, null);
        $dataset = $this->scope($definition)[$alias] ?? null;

        if ($dataset === null || $name === null) {
            return null;
        }

        $schema = $this->datasets->get($dataset)->schema();

        return $schema->hasField($name) ? $schema->field($name) : null;
    }

    /**
     * Every reason the definition cannot run. Empty when it can.
     *
     * @param  array<string, mixed>  $definition
     * @return list<string>
     */
    public function errors(array $definition): array
    {
        $errors = [];

        if ($this->scope($definition) === []) {
            return ['Choose a base dataset.'];
        }

        $aliases = [$definition['base']];

        foreach (array_values($definition['joins'] ?? []) as $index => $join) {
            $position = $index + 1;
            $alias = (string) ($join['alias'] ?? '');

            if (! array_key_exists($join['relation'] ?? '', $this->relationOptions($definition, $index))) {
                $errors[] = "Join #{$position}: choose a relation of a dataset already in the report.";
            }

            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias) !== 1) {
                $errors[] = "Join #{$position}: the alias may hold only letters, digits and underscores.";
            } elseif (in_array($alias, $aliases, true)) {
                $errors[] = "Join #{$position}: the alias \"{$alias}\" is already used; give the second one its own alias.";
            }

            $aliases[] = $alias;
        }

        $fields = $this->fieldOptions($definition);
        $grouped = self::isGrouped($definition);

        foreach ($definition['group_by'] ?? [] as $field) {
            if (! isset($fields[$field])) {
                $errors[] = "Group by: unknown field \"{$field}\".";
            }
        }

        foreach (array_values($definition['aggregates'] ?? []) as $index => $aggregate) {
            $position = $index + 1;
            $fn = $aggregate['fn'] ?? null;
            $field = $aggregate['field'] ?? null;

            if (! in_array($fn, self::AGGREGATES, true)) {
                $errors[] = "Aggregate #{$position}: unknown function.";
            } elseif ($fn !== 'count' && blank($field)) {
                $errors[] = "Aggregate #{$position}: {$fn} needs a field.";
            } elseif (filled($field) && ! isset($fields[$field])) {
                $errors[] = "Aggregate #{$position}: unknown field \"{$field}\".";
            } elseif (in_array($fn, ['sum', 'avg'], true) && ! $this->field($definition, $field)->type->isNumeric()) {
                $errors[] = "Aggregate #{$position}: {$fn} needs a numeric field, \"{$field}\" is not.";
            }

            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) ($aggregate['as'] ?? '')) !== 1) {
                $errors[] = "Aggregate #{$position}: the name may hold only letters, digits and underscores.";
            }
        }

        $outputs = $this->outputOptions($definition);

        foreach ($definition['columns'] ?? [] as $column) {
            if (! isset($outputs[$column['field'] ?? ''])) {
                $errors[] = $grouped
                    ? "Column \"{$column['field']}\": a grouped report shows only its group fields and aggregates."
                    : "Column \"{$column['field']}\": unknown field.";
            }
        }

        foreach ($definition['filters'] ?? [] as $filter) {
            $operator = $filter['operator'] ?? null;
            $value = $filter['value'] ?? null;

            if (! isset($fields[$filter['field'] ?? ''])) {
                $errors[] = "Filter on \"{$filter['field']}\": unknown field.";
            } elseif (! in_array($operator, self::OPERATORS, true)) {
                $errors[] = "Filter on \"{$filter['field']}\": unknown operator.";
            } elseif ($operator === 'between' && count(self::listValue($value)) !== 2) {
                $errors[] = "Filter on \"{$filter['field']}\": between needs two values, separated by a comma.";
            } elseif (! in_array($operator, ['null', 'not null'], true) && blank($value)) {
                $errors[] = "Filter on \"{$filter['field']}\": enter a value.";
            }
        }

        foreach ($definition['having'] ?? [] as $having) {
            if (! $grouped || ! in_array($having['column'] ?? '', array_column($definition['aggregates'] ?? [], 'as'), true)) {
                $errors[] = 'Having: choose one of the aggregates.';
            }
        }

        foreach ($definition['order_by'] ?? [] as $order) {
            if (! isset($outputs[$order['column'] ?? ''])) {
                $errors[] = "Order by \"{$order['column']}\": unknown column.";
            }
        }

        return $errors;
    }

    /**
     * Repeater state is keyed by item uuids; stored definitions are plain lists.
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    public static function normalize(array $definition): array
    {
        foreach (['joins', 'columns', 'filters', 'parameters', 'group_by', 'aggregates', 'having', 'order_by'] as $list) {
            $definition[$list] = array_values(array_filter((array) ($definition[$list] ?? []), fn (mixed $item): bool => filled($item)));
        }

        $definition['limit'] = filled($definition['limit'] ?? null) ? (int) $definition['limit'] : null;
        $definition['visualization'] = in_array($definition['visualization'] ?? null, self::VISUALIZATIONS, true) ? $definition['visualization'] : 'table';
        $preset = $definition['date_range']['preset'] ?? null;
        $definition['date_range'] = [
            'preset' => is_string($preset) && isset(DateRange::PRESETS[$preset]) ? $preset : 'all_time',
            'from' => $definition['date_range']['from'] ?? null,
            'to' => $definition['date_range']['to'] ?? null,
            'compare' => (bool) ($definition['date_range']['compare'] ?? false),
        ];

        return $definition;
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    public static function isGrouped(array $definition): bool
    {
        return array_filter($definition['group_by'] ?? []) !== [];
    }

    public static function joinedDataset(?string $relation): ?string
    {
        return $relation !== null && str_contains($relation, '>') ? explode('.', explode('>', $relation)[1])[0] : null;
    }

    /**
     * A list filter value as entered: comma separated text, or already a list.
     *
     * @return list<string>
     */
    public static function listValue(mixed $value): array
    {
        $values = is_array($value) ? $value : explode(',', (string) $value);

        return array_values(array_filter(array_map(fn (mixed $item): string => trim((string) $item), $values), fn (string $item): bool => $item !== ''));
    }

    /**
     * Typed the way the field is, so `5` compares with an int field as 5, not "5".
     */
    public static function coerce(?Field $field, mixed $value): mixed
    {
        return match ($field?->type) {
            FieldType::Int => (int) $value,
            FieldType::Decimal => (float) $value,
            FieldType::Bool => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            default => $value,
        };
    }
}
