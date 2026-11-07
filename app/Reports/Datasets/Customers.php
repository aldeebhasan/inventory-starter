<?php

namespace App\Reports\Datasets;

use Aldeebhasan\Panorama\Schema\Schema;
use App\Reports\TableDataset;

class Customers extends TableDataset
{
    public string $name = 'customers';

    protected string $table = 'customers';

    protected bool $softDeletes = true;

    protected function fields(): Schema
    {
        return Schema::make()
            ->primaryKey('id')
            ->int('id')
            ->string('name')
            ->string('email')
            ->string('phone')->nullable()
            ->string('tax_number')->nullable()
            ->bool('is_active')
            ->datetime('created_at')->nullable()
            ->datetime('updated_at')->nullable()
            ->bool('is_deleted');
    }
}
