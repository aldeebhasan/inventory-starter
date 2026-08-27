<?php

namespace App\Concerns;

use App\Models\StatusLog;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * @mixin Model
 */
trait TracksStatus
{
    public static function bootTracksStatus(): void
    {
        static::created(function (Model $model) {
            if ($model->status !== null) {
                $model->logStatusChange(null, $model->status);
            }
        });

        static::updating(function (Model $model) {
            if ($model->isDirty('status')) {
                $model->logStatusChange(
                    $model->getOriginal('status'),
                    $model->status,
                );
            }
        });
    }

    /** @return MorphMany<StatusLog, $this> */
    public function statusLogs(): MorphMany
    {
        return $this->morphMany(StatusLog::class, 'trackable')->orderBy('created_at');
    }

    /** @return MorphOne<StatusLog, $this> */
    public function latestStatusLog(): MorphOne
    {
        return $this->morphOne(StatusLog::class, 'trackable')->latestOfMany('created_at');
    }

    public function logStatusChange(
        string|BackedEnum|null $oldStatus,
        string|BackedEnum $newStatus,
        ?string $reason = null,
    ): StatusLog {
        return $this->statusLogs()->create([
            'old_status' => $oldStatus instanceof BackedEnum ? $oldStatus->value : $oldStatus,
            'new_status' => $newStatus instanceof BackedEnum ? $newStatus->value : $newStatus,
            'reason' => $reason,
            'created_by' => auth()->id(),
            'created_at' => now(),
        ]);
    }
}
