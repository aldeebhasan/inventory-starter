<?php

namespace App\Models;

use Aldeebhasan\Inventorix\Models\Reservation;
use Database\Factories\SaleOrderItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $sale_order_id
 * @property int $product_id
 * @property int|null $unit_id
 * @property float $quantity
 * @property float|null $unit_price
 * @property int|null $reservation_id
 * @property-read Product $product
 * @property-read Unit|null $unit
 * @property-read SaleOrder $saleOrder
 */
#[Fillable(['sale_order_id', 'product_id', 'unit_id', 'quantity', 'unit_price', 'reservation_id'])]
class SaleOrderItem extends Model
{
    /** @use HasFactory<SaleOrderItemFactory> */
    use HasFactory;

    protected $casts = [
        'quantity' => 'float',
        'unit_price' => 'float',
    ];

    public function saleOrder(): BelongsTo
    {
        return $this->belongsTo(SaleOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
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
