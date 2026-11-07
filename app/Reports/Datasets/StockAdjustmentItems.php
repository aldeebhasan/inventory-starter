<?php

namespace App\Reports\Datasets;

use Aldeebhasan\Panorama\Schema\Schema;
use App\Enums\StockAdjustmentItemStatus;
use App\Enums\StockAdjustmentOperation;
use App\Reports\TableDataset;

class StockAdjustmentItems extends TableDataset
{
    public string $name = 'stock_adjustment_items';

    protected string $table = 'stock_adjustment_items';

    protected ?string $dateColumn = 'created_at';

    protected function fields(): Schema
    {
        return Schema::make()
            ->primaryKey('id')
            ->int('id')
            ->int('stock_adjustment_id')->references('stock_adjustments.id')
            ->int('product_id')->references('products.id')
            ->enum('operation', array_column(StockAdjustmentOperation::cases(), 'value'))
            ->decimal('quantity', 4)
            ->decimal('cost', 4)->nullable()
            ->decimal('current_stock', 4)->nullable()
            ->enum('item_status', array_column(StockAdjustmentItemStatus::cases(), 'value'))
            ->string('failure_reason')->nullable()
            ->datetime('created_at')->nullable()
            ->datetime('updated_at')->nullable();
    }
}
