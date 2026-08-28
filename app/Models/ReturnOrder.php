<?php

namespace App\Models;

use App\Concerns\TracksStatus;
use App\Enums\ReturnOrderStatus;
use App\Enums\ReturnOrderType;
use Carbon\Carbon;
use Database\Factories\ReturnOrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $order_number
 * @property ReturnOrderType $type
 * @property ReturnOrderStatus $status
 * @property string|null $original_order_type
 * @property int|null $original_order_id
 * @property int|null $customer_id
 * @property int|null $supplier_id
 * @property int $location_id
 * @property string|null $reason
 * @property string|null $notes
 * @property int|null $created_by
 * @property Carbon $created_at
 * @property-read Collection<int, ReturnOrderItem> $items
 * @property-read Customer|null $customer
 * @property-read Supplier|null $supplier
 * @property-read Location $location
 * @property-read Model|null $originalOrder
 */
#[Fillable(['order_number', 'type', 'status', 'original_order_type', 'original_order_id', 'customer_id', 'supplier_id', 'location_id', 'reason', 'notes', 'created_by'])]
class ReturnOrder extends Model
{
    /** @use HasFactory<ReturnOrderFactory> */
    use HasFactory, SoftDeletes, TracksStatus;

    protected $casts = [
        'type' => ReturnOrderType::class,
        'status' => ReturnOrderStatus::class,
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (ReturnOrder $model) {
            if (! $model->order_number) {
                $prefix = $model->type === ReturnOrderType::CustomerReturn ? 'CRT' : 'SRT';
                $last = static::withTrashed()->where('type', $model->type)->max('order_number');
                $next = $last ? ((int) str_replace($prefix.'-', '', $last)) + 1 : 1;
                $model->order_number = $prefix.'-'.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
            }
        });
    }

    public function originalOrder(): MorphTo
    {
        return $this->morphTo();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReturnOrderItem::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
