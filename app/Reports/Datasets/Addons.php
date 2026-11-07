<?php

namespace App\Reports\Datasets;

use Aldeebhasan\Panorama\Schema\Schema;
use App\Reports\TableDataset;

class Addons extends TableDataset
{
    public string $name = 'addons';

    protected string $table = 'addons';

    protected bool $softDeletes = true;

    protected function fields(): Schema
    {
        return Schema::make()
            ->primaryKey('id')
            ->int('id')
            ->string('name')
            ->decimal('price', 3)
            ->bool('is_active')
            ->datetime('created_at')->nullable()
            ->datetime('updated_at')->nullable()
            ->bool('is_deleted');
    }
}
