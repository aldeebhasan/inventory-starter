<?php

namespace App\Models;

use Database\Factories\PurchaseOrderItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $purchase_order_id
 * @property int $product_id
 * @property int|null $unit_id
 * @property float $quantity
 * @property float|null $unit_cost
 * @property float $received_quantity
 * @property-read Product $product
 * @property-read Unit|null $unit
 * @property-read PurchaseOrder $purchaseOrder
 */
#[Fillable(['purchase_order_id', 'product_id', 'unit_id', 'quantity', 'unit_cost', 'received_quantity'])]
class PurchaseOrderItem extends Model
{
    /** @use HasFactory<PurchaseOrderItemFactory> */
    use HasFactory;

    protected $casts = [
        'quantity' => 'float',
        'unit_cost' => 'float',
        'received_quantity' => 'float',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * Quantity converted to the product's base unit for inventory operations.
     * If the line unit is a derived unit, multiplies by its conversion_factor.
     */
    public function convertedQuantity(): float
    {
        return $this->unit ? $this->unit->convertQty($this->quantity) : $this->quantity;
    }
}
