<?php

namespace App\Models;

use App\Enums\StockAdjustmentItemStatus;
use App\Enums\StockAdjustmentOperation;
use Database\Factories\StockAdjustmentItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $stock_adjustment_id
 * @property int $product_id
 * @property StockAdjustmentOperation $operation
 * @property float $quantity
 * @property float|null $cost
 * @property float|null $current_stock
 * @property StockAdjustmentItemStatus $item_status
 * @property string|null $failure_reason
 * @property-read Product $product
 * @property-read StockAdjustment $stockAdjustment
 */
#[Fillable(['stock_adjustment_id', 'product_id', 'operation', 'quantity', 'cost', 'current_stock', 'item_status', 'failure_reason'])]
class StockAdjustmentItem extends Model
{
    /** @use HasFactory<StockAdjustmentItemFactory> */
    use HasFactory;

    protected $casts = [
        'operation' => StockAdjustmentOperation::class,
        'item_status' => StockAdjustmentItemStatus::class,
        'quantity' => 'float',
        'cost' => 'float',
        'current_stock' => 'float',
    ];

    public function stockAdjustment(): BelongsTo
    {
        return $this->belongsTo(StockAdjustment::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
