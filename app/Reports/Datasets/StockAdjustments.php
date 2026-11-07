<?php

namespace App\Reports\Datasets;

use Aldeebhasan\Panorama\Schema\Schema;
use App\Enums\StockAdjustmentStatus;
use App\Reports\TableDataset;

class StockAdjustments extends TableDataset
{
    public string $name = 'stock_adjustments';

    protected string $table = 'stock_adjustments';

    protected ?string $dateColumn = 'created_at';

    protected bool $softDeletes = true;

    protected function fields(): Schema
    {
        return Schema::make()
            ->primaryKey('id')
            ->int('id')
            ->string('order_number')
            ->int('location_id')->references('locations.id')
            ->string('reason')
            ->enum('status', array_column(StockAdjustmentStatus::cases(), 'value'))
            ->datetime('created_at')->nullable()
            ->datetime('updated_at')->nullable()
            ->bool('is_deleted');
    }
}
