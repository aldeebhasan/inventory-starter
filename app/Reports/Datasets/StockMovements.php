<?php

namespace App\Reports\Datasets;

use Aldeebhasan\Inventorix\Enums\MovementType;
use Aldeebhasan\Panorama\Schema\Schema;
use App\Models\Product;
use App\Models\PurchaseOrderItem;
use App\Models\ReturnOrderItem;
use App\Models\SaleOrderItem;
use App\Models\StockAdjustmentItem;
use App\Models\TransferOrderItem;
use App\Reports\TableDataset;
use Illuminate\Database\Query\Builder;

class StockMovements extends TableDataset
{
    public string $name = 'stock_movements';

    protected string $table = 'inventorix_movements';

    protected ?string $dateColumn = 'created_at';

    protected string $morph = 'reference';

    protected array $morphLinks = [
        'purchase_order_item_id' => PurchaseOrderItem::class,
        'sale_order_item_id' => SaleOrderItem::class,
        'return_order_item_id' => ReturnOrderItem::class,
        'stock_adjustment_item_id' => StockAdjustmentItem::class,
        'transfer_order_item_id' => TransferOrderItem::class,
    ];

    /** Only products hold stock; the morph is exposed as a plain product_id. */
    protected function query(): Builder
    {
        return parent::query()
            ->addSelect('inventorix_movements.stockable_id as product_id')
            ->where('inventorix_movements.stockable_type', (new Product)->getMorphClass());
    }

    protected function fields(): Schema
    {
        return Schema::make()
            ->primaryKey('id')
            ->int('id')
            ->int('product_id')->references('products.id')
            ->int('location_id')->references('locations.id')
            ->int('transaction_id')->references('stock_transactions.id')
            ->enum('type', array_column(MovementType::cases(), 'value'))
            ->decimal('quantity', 4)
            ->decimal('before_quantity', 4)
            ->decimal('after_quantity', 4)
            ->decimal('cost_per_unit', 4)->nullable()
            ->string('lot_reference')->nullable()
            ->date('expires_at')->nullable()
            ->int('purchase_order_item_id')->nullable()->references('purchase_order_items.id')
            ->int('sale_order_item_id')->nullable()->references('sale_order_items.id')
            ->int('return_order_item_id')->nullable()->references('return_order_items.id')
            ->int('stock_adjustment_item_id')->nullable()->references('stock_adjustment_items.id')
            ->int('transfer_order_item_id')->nullable()->references('transfer_order_items.id')
            ->datetime('created_at')->nullable()
            ->datetime('updated_at')->nullable();
    }
}
