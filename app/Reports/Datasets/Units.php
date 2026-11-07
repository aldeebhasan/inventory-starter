<?php

namespace App\Reports\Datasets;

use Aldeebhasan\Panorama\Schema\Schema;
use App\Reports\TableDataset;

class Units extends TableDataset
{
    public string $name = 'units';

    protected string $table = 'units';

    protected function fields(): Schema
    {
        return Schema::make()
            ->primaryKey('id')
            ->int('id')
            ->string('name')
            ->string('abbreviation')
            ->int('base_unit_id')->nullable()->references('units.id')
            ->decimal('conversion_factor', 6)
            ->datetime('created_at')->nullable()
            ->datetime('updated_at')->nullable();
    }
}
