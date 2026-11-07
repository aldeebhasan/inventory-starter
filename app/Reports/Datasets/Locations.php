<?php

namespace App\Reports\Datasets;

use Aldeebhasan\Panorama\Schema\Schema;
use App\Enums\LocationType;
use App\Reports\TableDataset;
use Illuminate\Database\Query\Builder;

class Locations extends TableDataset
{
    public string $name = 'locations';

    protected string $table = 'inventorix_locations';

    /** The location type lives in the json meta column. */
    protected function query(): Builder
    {
        return parent::query()->addSelect('inventorix_locations.meta->type as type');
    }

    protected function fields(): Schema
    {
        return Schema::make()
            ->primaryKey('id')
            ->int('id')
            ->string('name')
            ->string('code')->nullable()
            ->enum('type', array_column(LocationType::cases(), 'value'))->nullable()
            ->int('parent_id')->nullable()->references('locations.id')
            ->bool('is_active')
            ->datetime('created_at')->nullable()
            ->datetime('updated_at')->nullable();
    }
}
