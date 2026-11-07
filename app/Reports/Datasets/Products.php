<?php

namespace App\Reports\Datasets;

use Aldeebhasan\Panorama\Schema\Schema;
use App\Enums\ProductType;
use App\Reports\TableDataset;

class Products extends TableDataset
{
    public string $name = 'products';

    protected string $table = 'products';

    protected bool $softDeletes = true;

    protected function fields(): Schema
    {
        return Schema::make()
            ->primaryKey('id')
            ->int('id')
            ->string('name')
            ->decimal('price', 3)
            ->decimal('cost', 3)
            ->enum('type', array_column(ProductType::cases(), 'value'))
            ->int('brand_id')->nullable()->references('brands.id')
            ->int('unit_id')->nullable()->references('units.id')
            ->datetime('created_at')->nullable()
            ->datetime('updated_at')->nullable()
            ->bool('is_deleted');
    }
}
