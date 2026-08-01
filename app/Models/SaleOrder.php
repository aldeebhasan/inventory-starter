<?php

namespace App\Models;

use App\Enums\SaleOrderStatus;
use Database\Factories\SaleOrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['order_number', 'customer_id', 'location_id', 'status', 'ordered_at', 'shipped_at', 'notes', 'created_by'])]
class SaleOrder extends Model
{
    /** @use HasFactory<SaleOrderFactory> */
    use HasFactory, SoftDeletes;

    protected $casts = [
        'status' => SaleOrderStatus::class,
        'ordered_at' => 'datetime',
        'shipped_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (SaleOrder $model) {
            $model->order_number = 'SO-'.str_pad(static::max('id') + 1, 5, '0', STR_PAD_LEFT);
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
