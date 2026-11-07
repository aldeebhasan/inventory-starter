<?php

namespace App\Reports\Datasets;

use Aldeebhasan\Panorama\Schema\Schema;
use App\Reports\TableDataset;

class ReturnOrderItems extends TableDataset
{
    public string $name = 'return_order_items';

    protected string $table = 'return_order_items';

    protected ?string $dateColumn = 'created_at';

    protected function fields(): Schema
    {
        return Schema::make()
            ->primaryKey('id')
            ->int('id')
            ->int('return_order_id')->references('return_orders.id')
            ->int('product_id')->references('products.id')
            ->int('unit_id')->nullable()->references('units.id')
            ->decimal('quantity', 3)
            ->decimal('price', 3)->nullable()
            ->datetime('created_at')->nullable()
            ->datetime('updated_at')->nullable();
    }
}
