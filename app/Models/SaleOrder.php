<?php

namespace App\Models;

use App\Concerns\TracksStatus;
use App\Enums\SaleOrderStatus;
use Carbon\Carbon;
use Database\Factories\SaleOrderFactory;
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
 * @property int $customer_id
 * @property int $location_id
 * @property SaleOrderStatus $status
 * @property Carbon $ordered_at
 * @property Carbon|null $shipped_at
 * @property string|null $notes
 * @property int|null $created_by
 * @property-read Collection<int, SaleOrderItem> $items
 * @property-read Customer $customer
 * @property-read Location $location
 */
#[Fillable(['order_number', 'customer_id', 'location_id', 'status', 'ordered_at', 'shipped_at', 'notes', 'created_by'])]
class SaleOrder extends Model
{
    /** @use HasFactory<SaleOrderFactory> */
    use HasFactory, SoftDeletes, TracksStatus;

    protected $casts = [
        'status' => SaleOrderStatus::class,
        'ordered_at' => 'datetime',
        'shipped_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (SaleOrder $model) {
            $last = static::withTrashed()->max('order_number');
            $next = $last ? ((int) str_replace('SO-', '', $last)) + 1 : 1;
            $model->order_number = 'SO-'.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleOrderItem::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
