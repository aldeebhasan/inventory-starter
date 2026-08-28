<?php

namespace App\Models;

use Database\Factories\ReturnOrderItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $return_order_id
 * @property int $product_id
 * @property float $quantity
 * @property float|null $price
 * @property-read ReturnOrder $returnOrder
 * @property-read Product $product
 */
#[Fillable(['return_order_id', 'product_id', 'quantity', 'price'])]
class ReturnOrderItem extends Model
{
    /** @use HasFactory<ReturnOrderItemFactory> */
    use HasFactory;

    protected $casts = [
        'quantity' => 'float',
        'price' => 'float',
    ];

    public function returnOrder(): BelongsTo
    {
        return $this->belongsTo(ReturnOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
