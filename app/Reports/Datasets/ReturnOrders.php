<?php

namespace App\Reports\Datasets;

use Aldeebhasan\Panorama\Schema\Schema;
use App\Enums\ReturnOrderStatus;
use App\Enums\ReturnOrderType;
use App\Reports\TableDataset;

class ReturnOrders extends TableDataset
{
    public string $name = 'return_orders';

    protected string $table = 'return_orders';

    protected ?string $dateColumn = 'created_at';

    protected bool $softDeletes = true;

    protected function fields(): Schema
    {
        return Schema::make()
            ->primaryKey('id')
            ->int('id')
            ->string('order_number')
            ->enum('type', array_column(ReturnOrderType::cases(), 'value'))
            ->enum('status', array_column(ReturnOrderStatus::cases(), 'value'))
            ->int('sale_order_id')->nullable()->references('sale_orders.id')
            ->int('purchase_order_id')->nullable()->references('purchase_orders.id')
            ->int('customer_id')->nullable()->references('customers.id')
            ->int('supplier_id')->nullable()->references('suppliers.id')
            ->int('location_id')->references('locations.id')
            ->string('reason')->nullable()
            ->datetime('created_at')->nullable()
            ->datetime('updated_at')->nullable()
            ->bool('is_deleted');
    }
}
