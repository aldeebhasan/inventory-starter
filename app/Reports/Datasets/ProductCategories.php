<?php

namespace App\Reports\Datasets;

use Aldeebhasan\Panorama\Dataset\Params;
use Aldeebhasan\Panorama\Schema\Schema;
use App\Reports\TableDataset;
use Illuminate\Support\Facades\DB;

class ProductCategories extends TableDataset
{
    public string $name = 'product_categories';

    protected string $table = 'category_product';

    /** A pivot without an id: chunked by its composite key instead. */
    public function fetch(Params $params): iterable
    {
        return DB::table($this->table)->orderBy('product_id')->orderBy('category_id')->lazy(1000);
    }

    protected function fields(): Schema
    {
        return Schema::make()
            ->int('product_id')->references('products.id')
            ->int('category_id')->references('categories.id');
    }
}
