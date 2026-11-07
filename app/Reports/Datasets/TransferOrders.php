<?php

namespace App\Reports\Datasets;

use Aldeebhasan\Panorama\Schema\Schema;
use App\Enums\TransferOrderStatus;
use App\Reports\TableDataset;

class TransferOrders extends TableDataset
{
    public string $name = 'transfer_orders';

    protected string $table = 'transfer_orders';

    protected ?string $dateColumn = 'created_at';

    protected bool $softDeletes = true;

    protected function fields(): Schema
    {
        return Schema::make()
            ->primaryKey('id')
            ->int('id')
            ->string('order_number')
            ->int('from_location_id')->references('locations.id')
            ->int('to_location_id')->references('locations.id')
            ->enum('status', array_column(TransferOrderStatus::cases(), 'value'))
            ->datetime('created_at')->nullable()
            ->datetime('updated_at')->nullable()
            ->bool('is_deleted');
    }
}
