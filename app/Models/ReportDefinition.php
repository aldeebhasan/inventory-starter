<?php

namespace App\Models;

use Aldeebhasan\Panorama\History\RecordedRun;
use Database\Factories\ReportDefinitionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string|null $description
 * @property bool $is_shared
 * @property array{base: string, joins?: list<array{relation: string, alias: string, type?: string}>, columns?: list<array{field: string, label?: string|null, format?: string, total?: bool}>, filters?: list<array{field: string, operator: string, value?: mixed}>, parameters?: list<string>, group_by?: list<string>, aggregates?: list<array{fn: string, field?: string|null, as: string}>, having?: list<array{column: string, operator: string, value: mixed}>, order_by?: list<array{column: string, direction?: string}>, limit?: int|null} $definition
 */
#[Fillable(['user_id', 'name', 'description', 'is_shared', 'definition'])]
class ReportDefinition extends Model
{
    /** @use HasFactory<ReportDefinitionFactory> */
    use HasFactory, SoftDeletes;

    protected $casts = [
        'is_shared' => 'boolean',
        'definition' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(fn (Builder $query) => $query->where('user_id', $user->id)->orWhere('is_shared', true));
    }

    public function isOwnedBy(?User $user): bool
    {
        return $user !== null && $this->user_id === $user->id;
    }

    public function isVisibleTo(?User $user): bool
    {
        return $this->is_shared || $this->isOwnedBy($user);
    }

    /**
     * The key Panorama runs, caches and records this report under. It carries the last edit, so rows cached for an
     * older definition are never served, whatever parameters they were cached with.
     */
    public function reportKey(): string
    {
        return "defined-{$this->id}-{$this->updated_at?->getTimestamp()}";
    }

    /**
     * Every recorded run of this report, across its edits.
     *
     * @return Builder<RecordedRun>
     */
    public function runs(): Builder
    {
        return RecordedRun::query()->where('report', 'like', "defined-{$this->id}-%");
    }
}
