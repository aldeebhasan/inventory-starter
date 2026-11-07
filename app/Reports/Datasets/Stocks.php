<?php

namespace App\Reports\Datasets;

use Aldeebhasan\Panorama\Schema\Schema;
use App\Models\Product;
use App\Reports\TableDataset;
use Illuminate\Database\Query\Builder;

class Stocks extends TableDataset
{
    public string $name = 'stocks';

    protected string $table = 'inventorix_stocks';

    /** Only products hold stock; the morph is exposed as a plain product_id. */
    protected function query(): Builder
    {
        return parent::query()
            ->addSelect('inventorix_stocks.stockable_id as product_id')
            ->where('inventorix_stocks.stockable_type', (new Product)->getMorphClass());
    }

    protected function fields(): Schema
    {
        return Schema::make()
            ->primaryKey('id')
            ->int('id')
            ->int('product_id')->references('products.id')
            ->int('location_id')->references('locations.id')
            ->decimal('quantity', 4)
            ->decimal('reserved_quantity', 4)
            ->datetime('created_at')->nullable()
            ->datetime('updated_at')->nullable();
    }
}
