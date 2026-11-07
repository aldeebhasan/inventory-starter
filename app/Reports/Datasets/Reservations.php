<?php

namespace App\Reports\Datasets;

use Aldeebhasan\Inventorix\Enums\ReservationStatus;
use Aldeebhasan\Panorama\Schema\Schema;
use App\Models\Product;
use App\Models\SaleOrderItem;
use App\Reports\TableDataset;
use Illuminate\Database\Query\Builder;

class Reservations extends TableDataset
{
    public string $name = 'reservations';

    protected string $table = 'inventorix_reservations';

    protected ?string $dateColumn = 'created_at';

    protected string $morph = 'reference';

    protected array $morphLinks = [
        'sale_order_item_id' => SaleOrderItem::class,
    ];

    /** Only products hold stock; the morph is exposed as a plain product_id. */
    protected function query(): Builder
    {
        return parent::query()
            ->addSelect('inventorix_reservations.stockable_id as product_id')
            ->where('inventorix_reservations.stockable_type', (new Product)->getMorphClass());
    }

    protected function fields(): Schema
    {
        return Schema::make()
            ->primaryKey('id')
            ->int('id')
            ->int('product_id')->references('products.id')
            ->int('location_id')->references('locations.id')
            ->decimal('quantity', 4)
            ->enum('status', array_column(ReservationStatus::cases(), 'value'))
            ->datetime('expires_at')->nullable()
            ->int('sale_order_item_id')->nullable()->references('sale_order_items.id')
            ->datetime('created_at')->nullable()
            ->datetime('updated_at')->nullable();
    }
}
