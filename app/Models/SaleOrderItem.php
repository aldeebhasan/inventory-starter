<?php

namespace App\Models;

use Aldeebhasan\Inventorix\Models\Reservation;
use Database\Factories\SaleOrderItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['sale_order_id', 'product_id', 'quantity', 'unit_price', 'reservation_id'])]
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

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }
}
