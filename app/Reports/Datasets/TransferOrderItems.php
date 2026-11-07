<?php

namespace App\Reports\Datasets;

use Aldeebhasan\Panorama\Schema\Schema;
use App\Enums\TransferOrderItemStatus;
use App\Reports\TableDataset;

class TransferOrderItems extends TableDataset
{
    public string $name = 'transfer_order_items';

    protected string $table = 'transfer_order_items';

    protected ?string $dateColumn = 'created_at';

    protected function fields(): Schema
    {
        return Schema::make()
            ->primaryKey('id')
            ->int('id')
            ->int('transfer_order_id')->references('transfer_orders.id')
            ->int('product_id')->references('products.id')
            ->decimal('quantity', 3)
            ->enum('item_status', array_column(TransferOrderItemStatus::cases(), 'value'))
            ->string('failure_reason')->nullable()
            ->datetime('created_at')->nullable()
            ->datetime('updated_at')->nullable();
    }
}
