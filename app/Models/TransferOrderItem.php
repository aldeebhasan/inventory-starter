<?php

namespace App\Models;

use App\Enums\TransferOrderItemStatus;
use Database\Factories\TransferOrderItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $transfer_order_id
 * @property int $product_id
 * @property float $quantity
 * @property TransferOrderItemStatus $item_status
 * @property string|null $failure_reason
 * @property-read TransferOrder $transferOrder
 * @property-read Product $product
 */
#[Fillable(['transfer_order_id', 'product_id', 'quantity', 'item_status', 'failure_reason'])]
class TransferOrderItem extends Model
{
    /** @use HasFactory<TransferOrderItemFactory> */
    use HasFactory;

    protected $casts = [
        'quantity' => 'float',
        'item_status' => TransferOrderItemStatus::class,
    ];

    public function transferOrder(): BelongsTo
    {
        return $this->belongsTo(TransferOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
