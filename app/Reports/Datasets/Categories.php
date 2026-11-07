<?php

namespace App\Reports\Datasets;

use Aldeebhasan\Panorama\Schema\Schema;
use App\Reports\TableDataset;

class Categories extends TableDataset
{
    public string $name = 'categories';

    protected string $table = 'categories';

    protected bool $softDeletes = true;

    protected function fields(): Schema
    {
        return Schema::make()
            ->primaryKey('id')
            ->int('id')
            ->string('name')
            ->datetime('created_at')->nullable()
            ->datetime('updated_at')->nullable()
            ->bool('is_deleted');
    }
}
