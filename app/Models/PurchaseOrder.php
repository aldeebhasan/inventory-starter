<?php

namespace App\Models;

use App\Enums\PurchaseOrderStatus;
use Carbon\Carbon;
use Database\Factories\PurchaseOrderFactory;
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
 * @property int $supplier_id
 * @property int $location_id
 * @property PurchaseOrderStatus $status
 * @property Carbon $ordered_at
 * @property Carbon|null $received_at
 * @property string|null $notes
 * @property int|null $created_by
 * @property-read Collection<int, PurchaseOrderItem> $items
 * @property-read Supplier $supplier
 * @property-read Location $location
 */
#[Fillable(['order_number', 'supplier_id', 'location_id', 'status', 'ordered_at', 'received_at', 'notes', 'created_by'])]
class PurchaseOrder extends Model
{
    /** @use HasFactory<PurchaseOrderFactory> */
    use HasFactory, SoftDeletes;

    protected $casts = [
        'status' => PurchaseOrderStatus::class,
        'ordered_at' => 'datetime',
        'received_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (PurchaseOrder $order) {
            $order->order_number = 'PO-'.str_pad((string) ((int) static::query()->max('id') + 1), 5, '0', STR_PAD_LEFT);
        });
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
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
