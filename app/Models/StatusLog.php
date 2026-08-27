<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $id
 * @property string $trackable_type
 * @property int $trackable_id
 * @property string|null $old_status
 * @property string $new_status
 * @property string|null $reason
 * @property int|null $created_by
 * @property Carbon $created_at
 * @property-read Model $trackable
 * @property-read User|null $creator
 */
class StatusLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'trackable_type',
        'trackable_id',
        'old_status',
        'new_status',
        'reason',
        'created_by',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function trackable(): MorphTo
    {
        return $this->morphTo();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
