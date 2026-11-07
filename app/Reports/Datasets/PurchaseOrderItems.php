<?php

namespace App\Reports\Datasets;

use Aldeebhasan\Panorama\Schema\Schema;
use App\Reports\TableDataset;

class PurchaseOrderItems extends TableDataset
{
    public string $name = 'purchase_order_items';

    protected string $table = 'purchase_order_items';

    protected ?string $dateColumn = 'created_at';

    protected function fields(): Schema
    {
        return Schema::make()
            ->primaryKey('id')
            ->int('id')
            ->int('purchase_order_id')->references('purchase_orders.id')
            ->int('product_id')->references('products.id')
            ->int('unit_id')->nullable()->references('units.id')
            ->decimal('quantity', 3)
            ->decimal('unit_cost', 3)->nullable()
            ->decimal('received_quantity', 3)
            ->datetime('created_at')->nullable()
            ->datetime('updated_at')->nullable();
    }
}
