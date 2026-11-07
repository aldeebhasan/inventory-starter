<?php

namespace App\Reports\Datasets;

use Aldeebhasan\Panorama\Schema\Schema;
use App\Enums\PurchaseOrderStatus;
use App\Reports\TableDataset;

class PurchaseOrders extends TableDataset
{
    public string $name = 'purchase_orders';

    protected string $table = 'purchase_orders';

    protected ?string $dateColumn = 'created_at';

    protected bool $softDeletes = true;

    protected function fields(): Schema
    {
        return Schema::make()
            ->primaryKey('id')
            ->int('id')
            ->string('order_number')
            ->int('supplier_id')->references('suppliers.id')
            ->int('location_id')->references('locations.id')
            ->enum('status', array_column(PurchaseOrderStatus::cases(), 'value'))
            ->datetime('ordered_at')->nullable()
            ->datetime('received_at')->nullable()
            ->datetime('created_at')->nullable()
            ->datetime('updated_at')->nullable()
            ->bool('is_deleted');
    }
}
