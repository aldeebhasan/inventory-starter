<?php

namespace App\Reports;

use Aldeebhasan\Panorama\Dataset\Datasets;
use Aldeebhasan\Panorama\Query\Aggregate;
use Aldeebhasan\Panorama\Query\DatasetQuery;
use Aldeebhasan\Panorama\Report\Column;
use Aldeebhasan\Panorama\Report\Report;
use App\Models\ReportDefinition;
use App\Models\User;

/**
 * A report built in the Report Builder: the saved definition, read as a Panorama query.
 *
 * Extends Report rather than only implementing the contract: Panorama caches rows only for Report instances.
 */
class DefinedReport extends Report
{
    // Rows are the same for every user (datasets are not user-scoped), so the user-blind cache key is safe.
    protected int $cacheFor = 300;

    private readonly ReportSchema $schema;

    /**
     * @param  string|null  $key  set for a preview, which caches apart from the full result
     * @param  int|null  $rowLimit  the most rows a preview reads
     */
    public function __construct(
        public readonly ReportDefinition $definition,
        ?string $key = null,
        private readonly ?int $rowLimit = null,
    ) {
        $this->key = $key ?? $definition->reportKey();
        $this->name = $definition->name ?? 'Untitled report';
        $this->schema = app(ReportSchema::class);
    }

    public function parameters(): array
    {
        $parameters = $this->definition->definition['parameters'] ?? [];

        return array_filter([
            'from' => in_array('from', $parameters, true) ? ['required', 'date'] : null,
            'to' => in_array('to', $parameters, true) ? ['required', 'date', ...(in_array('from', $parameters, true) ? ['after_or_equal:from'] : [])] : null,
        ]);
    }

    public function query(Datasets $datasets): DatasetQuery
    {
        $definition = $this->definition->definition;
        $query = $datasets->from($definition['base']);

        foreach ($definition['joins'] ?? [] as $join) {
            [$local, $target] = explode('>', $join['relation']);
            [$dataset, $field] = explode('.', $target);
            $name = $join['alias'] === $dataset ? $dataset : "{$dataset} as {$join['alias']}";

            ($join['type'] ?? 'inner') === 'left'
                ? $query->leftJoin($name, $local, "{$join['alias']}.{$field}")
                : $query->join($name, $local, "{$join['alias']}.{$field}");
        }

        foreach ($definition['filters'] ?? [] as $filter) {
            $this->applyFilter($query, $definition, $filter);
        }

        $aggregates = collect($definition['aggregates'] ?? [])->mapWithKeys(fn (array $aggregate): array => [
            $aggregate['as'] => match ($aggregate['fn']) {
                'count' => Aggregate::count(blank($aggregate['field'] ?? null) ? null : $aggregate['field']),
                default => Aggregate::{$aggregate['fn']}($aggregate['field']),
            },
        ])->all();

        if (ReportSchema::isGrouped($definition)) {
            $query->groupBy(array_map(self::aliased(...), $definition['group_by']), $aggregates);
        } elseif ($aggregates !== []) {
            $query->aggregate($aggregates);
        } elseif (filled($definition['columns'] ?? [])) {
            $query->select(...array_map(fn (array $column): string => self::aliased($column['field']), $definition['columns']));
        }

        foreach ($definition['having'] ?? [] as $having) {
            $query->having($having['column'], $having['operator'], (float) $having['value']);
        }

        // Grouped rows are ordered by their output names; ungrouped ones by the source field, which needs no select.
        $grouped = ReportSchema::isGrouped($definition);

        foreach ($definition['order_by'] ?? [] as $order) {
            $query->orderBy($grouped ? self::output($order['column']) : $order['column'], $order['direction'] ?? 'asc');
        }

        $limit = array_filter([$definition['limit'] ?? null, $this->rowLimit]);

        if ($limit !== []) {
            $query->limit((int) min($limit));
        }

        return $query;
    }

    public function columns(): array
    {
        $editor = app(DefinitionEditor::class);

        return array_map(function (array $column) use ($editor): Column {
            $made = Column::make(self::output($column['field']))->label($editor->columnLabel($this->definition->definition, $column));

            $made = match ($column['format'] ?? 'text') {
                'integer' => $made->number(0),
                'number' => $made->number(2),
                'money' => $made->money('USD'),
                'percent' => $made->percent(),
                'date' => $made->date(),
                'datetime' => $made->datetime(),
                default => $made,
            };

            return $made->total((bool) ($column['total'] ?? false));
        }, $this->definition->definition['columns'] ?? []);
    }

    /**
     * The columns the preview shows: the chosen ones, or every column the query outputs when none were chosen.
     *
     * @return list<Column>
     */
    public function previewColumns(): array
    {
        $columns = $this->columns();

        if ($columns !== []) {
            return $columns;
        }

        $definition = $this->definition->definition;
        $aggregates = array_column($definition['aggregates'] ?? [], 'as');

        if (ReportSchema::isGrouped($definition)) {
            $names = [...array_map(self::output(...), $definition['group_by']), ...$aggregates];
        } elseif ($aggregates !== []) {
            $names = $aggregates;
        } else {
            // As Panorama names an unselected projection: the bare field, or alias.field when two aliases share it.
            $fields = array_keys($this->schema->fieldOptions($definition));
            $seen = array_count_values(array_map(fn (string $field): string => explode('.', $field, 2)[1], $fields));
            $names = array_map(fn (string $field): string => $seen[explode('.', $field, 2)[1]] > 1 ? $field : explode('.', $field, 2)[1], $fields);
        }

        return array_map(fn (string $name): Column => Column::make($name)->label($name), $names);
    }

    public function authorize(mixed $user = null): bool
    {
        return $user instanceof User && $this->definition->isVisibleTo($user);
    }

    /** `orders.amount` is output as `orders__amount`, so two datasets' same-named fields never collide. */
    public static function output(string $column): string
    {
        return str_replace('.', '__', $column);
    }

    private static function aliased(string $field): string
    {
        return str_contains($field, '.') ? $field.' as '.self::output($field) : $field;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array{field: string, operator: string, value?: mixed}  $filter
     */
    private function applyFilter(DatasetQuery $query, array $definition, array $filter): void
    {
        $field = $this->schema->field($definition, $filter['field']);
        $values = array_map(fn (string $value): mixed => ReportSchema::coerce($field, $value), ReportSchema::listValue($filter['value'] ?? null));

        match ($filter['operator']) {
            'in' => $query->whereIn($filter['field'], $values),
            'not in' => $query->whereNotIn($filter['field'], $values),
            'between' => $query->whereBetween($filter['field'], $values[0], $values[1]),
            'null' => $query->whereNull($filter['field']),
            'not null' => $query->whereNotNull($filter['field']),
            default => $query->where($filter['field'], $filter['operator'], ReportSchema::coerce($field, $filter['value'])),
        };
    }
}
