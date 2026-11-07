<?php

namespace App\Reports\Datasets;

use Aldeebhasan\Panorama\Schema\Schema;
use App\Reports\TableDataset;

class Suppliers extends TableDataset
{
    public string $name = 'suppliers';

    protected string $table = 'suppliers';

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
            ->datetime('updated_at')->nullable();
    }
}
