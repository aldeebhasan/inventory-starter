<?php

namespace App\Reports\Datasets;

use Aldeebhasan\Panorama\Schema\Schema;
use App\Reports\TableDataset;

class SaleOrderItems extends TableDataset
{
    public string $name = 'sale_order_items';

    protected string $table = 'sale_order_items';

    protected ?string $dateColumn = 'created_at';

    protected function fields(): Schema
    {
        return Schema::make()
            ->primaryKey('id')
            ->int('id')
            ->int('sale_order_id')->references('sale_orders.id')
            ->int('product_id')->references('products.id')
            ->int('unit_id')->nullable()->references('units.id')
            ->decimal('quantity', 3)
            ->decimal('unit_price', 3)->nullable()
            ->int('reservation_id')->nullable()->references('reservations.id')
            ->datetime('created_at')->nullable()
            ->datetime('updated_at')->nullable();
    }
}
