<?php

namespace App\Models;

use App\Enums\StockAdjustmentItemStatus;
use App\Enums\StockAdjustmentStatus;
use Database\Factories\StockAdjustmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $order_number
 * @property int $location_id
 * @property string $reason
 * @property string|null $notes
 * @property StockAdjustmentStatus $status
 * @property int|null $created_by
 * @property-read Collection<int, StockAdjustmentItem> $items
 * @property-read Location $location
 * @property-read User|null $createdBy
 */
#[Fillable(['order_number', 'location_id', 'reason', 'notes', 'status', 'created_by'])]
class StockAdjustment extends Model
{
    /** @use HasFactory<StockAdjustmentFactory> */
    use HasFactory, SoftDeletes;

    protected $casts = [
        'status' => StockAdjustmentStatus::class,
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (StockAdjustment $model) {
            if (empty($model->order_number)) {
                $model->order_number = 'ADJ-'.str_pad(
                    (string) ((int) static::withTrashed()->max('id') + 1),
                    5, '0', STR_PAD_LEFT
                );
            }
        });
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockAdjustmentItem::class);
    }

    public function failedItems(): HasMany
    {
        return $this->hasMany(StockAdjustmentItem::class)
            ->where('item_status', StockAdjustmentItemStatus::Failed);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Recompute order status from item statuses after the batch finishes.
     */
    public function syncStatusFromItems(): void
    {
        $total = $this->items()->count();
        $applied = $this->items()->where('item_status', StockAdjustmentItemStatus::Applied)->count();

        $this->update([
            'status' => $applied === $total
                ? StockAdjustmentStatus::Applied
                : StockAdjustmentStatus::PartiallyApplied,
        ]);
    }
}
