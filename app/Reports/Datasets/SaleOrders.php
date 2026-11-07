<?php

namespace App\Reports\Datasets;

use Aldeebhasan\Panorama\Schema\Schema;
use App\Enums\SaleOrderStatus;
use App\Reports\TableDataset;

class SaleOrders extends TableDataset
{
    public string $name = 'sale_orders';

    protected string $table = 'sale_orders';

    protected ?string $dateColumn = 'created_at';

    protected bool $softDeletes = true;

    protected function fields(): Schema
    {
        return Schema::make()
            ->primaryKey('id')
            ->int('id')
            ->string('order_number')
            ->int('customer_id')->references('customers.id')
            ->int('location_id')->references('locations.id')
            ->enum('status', array_column(SaleOrderStatus::cases(), 'value'))
            ->datetime('ordered_at')->nullable()
            ->datetime('shipped_at')->nullable()
            ->datetime('created_at')->nullable()
            ->datetime('updated_at')->nullable()
            ->bool('is_deleted');
    }
}
