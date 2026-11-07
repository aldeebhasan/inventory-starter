<?php

namespace App\Reports;

use Aldeebhasan\Panorama\Dataset\Dataset;
use Aldeebhasan\Panorama\Dataset\Params;
use Aldeebhasan\Panorama\Schema\FieldType;
use Aldeebhasan\Panorama\Schema\Schema;
use Generator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A dataset over one application table, read in id-ordered chunks so any size costs one chunk of memory.
 *
 * Lives outside app/Reports/Datasets because discovery registers every Dataset subclass found there.
 */
abstract class TableDataset extends Dataset
{
    protected string $table;

    /** The column the `from` / `to` run-time parameters narrow. Null for master data, which is never date-filtered. */
    protected ?string $dateColumn = null;

    protected bool $softDeletes = false;

    /** @var array<string, class-string> typed id field => morph class, read from `{$morph}_type` / `{$morph}_id` */
    protected array $morphLinks = [];

    protected string $morph = '';

    /** Time buckets every date and datetime field gets, as `{field}__{bucket}` string fields. */
    public const BUCKETS = ['day', 'week', 'month', 'year'];

    /** The fields the table provides; schema() adds the time buckets. */
    abstract protected function fields(): Schema;

    public function schema(): Schema
    {
        $schema = $this->fields();

        foreach ($this->dateFields($schema) as $field) {
            foreach (self::BUCKETS as $bucket) {
                $schema->string("{$field}__{$bucket}")->nullable();
            }
        }

        return $schema;
    }

    /**
     * @return array<string, mixed>
     */
    public function parameters(): array
    {
        return $this->dateColumn === null ? [] : [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ];
    }

    public function fetch(Params $params): iterable
    {
        $column = "{$this->table}.{$this->dateColumn}";

        return $this->query()
            ->when($this->dateColumn !== null && $params->filled('from'), fn (Builder $query) => $query->where($column, '>=', Carbon::parse($params->string('from'))->startOfDay()))
            // `<` next day rather than whereDate(), so an index on the column still applies.
            ->when($this->dateColumn !== null && $params->filled('to'), fn (Builder $query) => $query->where($column, '<', Carbon::parse($params->string('to'))->addDay()->startOfDay()))
            ->lazyById(1000, "{$this->table}.id", 'id')
            ->pipe(fn (iterable $rows): Generator => $this->bucketed($rows));
    }

    /**
     * Adds the time buckets while rows stream, in PHP so they read the same on MariaDB and SQLite.
     *
     * @param  iterable<object>  $rows
     * @return Generator<int, array<string, mixed>>
     */
    protected function bucketed(iterable $rows): Generator
    {
        $fields = $this->dateFields($this->fields());

        foreach ($rows as $row) {
            $row = (array) $row;

            foreach ($fields as $field) {
                $value = $row[$field] ?? null;
                $day = $value === null ? null : substr((string) $value, 0, 10);

                $row["{$field}__day"] = $day;
                $row["{$field}__week"] = $day === null ? null : date('o-\\WW', (int) strtotime($day));
                $row["{$field}__month"] = $day === null ? null : substr($day, 0, 7);
                $row["{$field}__year"] = $day === null ? null : substr($day, 0, 4);
            }

            yield $row;
        }
    }

    /**
     * @return list<string>
     */
    private function dateFields(Schema $schema): array
    {
        return array_keys(array_filter(
            $schema->fields(),
            fn ($field): bool => in_array($field->type, [FieldType::Date, FieldType::Datetime], true),
        ));
    }

    protected function query(): Builder
    {
        $query = DB::table($this->table)->select("{$this->table}.*");

        if ($this->softDeletes) {
            $query->selectRaw("{$this->table}.deleted_at is not null as is_deleted");
        }

        foreach ($this->morphLinks as $field => $class) {
            $query->selectRaw(
                "case when {$this->table}.{$this->morph}_type = ? then {$this->table}.{$this->morph}_id end as {$field}",
                [$class],
            );
        }

        return $query;
    }
}
