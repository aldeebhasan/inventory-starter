<?php

namespace App\Models;

use App\Concerns\TracksStatus;
use App\Enums\TransferOrderItemStatus;
use App\Enums\TransferOrderStatus;
use Database\Factories\TransferOrderFactory;
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
 * @property int $from_location_id
 * @property int $to_location_id
 * @property TransferOrderStatus $status
 * @property string|null $notes
 * @property int|null $created_by
 * @property-read Collection<int, TransferOrderItem> $items
 * @property-read Location $fromLocation
 * @property-read Location $toLocation
 * @property-read User|null $createdBy
 */
#[Fillable(['order_number', 'from_location_id', 'to_location_id', 'status', 'notes', 'created_by'])]
class TransferOrder extends Model
{
    /** @use HasFactory<TransferOrderFactory> */
    use HasFactory, SoftDeletes, TracksStatus;

    protected $casts = [
        'status' => TransferOrderStatus::class,
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (TransferOrder $model) {
            if (empty($model->order_number)) {
                $model->order_number = 'TO-'.str_pad(
                    (string) ((int) static::withTrashed()->max('id') + 1),
                    5, '0', STR_PAD_LEFT
                );
            }
        });
    }

    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'from_location_id');
    }

    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'to_location_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(TransferOrderItem::class);
    }

    public function pendingItems(): HasMany
    {
        return $this->items()->where('item_status', TransferOrderItemStatus::Pending);
    }

    public function sentItems(): HasMany
    {
        return $this->items()->where('item_status', TransferOrderItemStatus::Sent);
    }

    public function failedItems(): HasMany
    {
        return $this->items()->where('item_status', TransferOrderItemStatus::Failed);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function syncStatusFromItems(string $phase): void
    {
        $total = $this->items()->count();

        if ($phase === 'send') {
            $sent = $this->items()->where('item_status', TransferOrderItemStatus::Sent)->count();
            $failed = $this->items()->where('item_status', TransferOrderItemStatus::Failed)->count();

            $this->update([
                'status' => match (true) {
                    $sent === $total => TransferOrderStatus::InTransit,
                    $failed > 0 => TransferOrderStatus::PartiallySent,
                    default => TransferOrderStatus::Sending,
                },
            ]);
        }

        if ($phase === 'receive') {
            $received = $this->items()->where('item_status', TransferOrderItemStatus::Received)->count();
            $failed = $this->items()->where('item_status', TransferOrderItemStatus::Failed)->count();

            $this->update([
                'status' => match (true) {
                    $received === $total => TransferOrderStatus::Completed,
                    $failed > 0 => TransferOrderStatus::PartiallyCompleted,
                    default => TransferOrderStatus::Receiving,
                },
            ]);
        }
    }
}
