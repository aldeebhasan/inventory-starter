<?php

namespace App\Reports\Datasets;

use Aldeebhasan\Inventorix\Enums\TransactionStatus;
use Aldeebhasan\Inventorix\Enums\TransactionType;
use Aldeebhasan\Panorama\Schema\Schema;
use App\Models\PurchaseOrder;
use App\Models\ReturnOrder;
use App\Models\SaleOrder;
use App\Models\StockAdjustment;
use App\Models\TransferOrder;
use App\Reports\TableDataset;

class StockTransactions extends TableDataset
{
    public string $name = 'stock_transactions';

    protected string $table = 'inventorix_transactions';

    protected ?string $dateColumn = 'created_at';

    protected string $morph = 'causable';

    protected array $morphLinks = [
        'purchase_order_id' => PurchaseOrder::class,
        'sale_order_id' => SaleOrder::class,
        'return_order_id' => ReturnOrder::class,
        'stock_adjustment_id' => StockAdjustment::class,
        'transfer_order_id' => TransferOrder::class,
    ];

    protected function fields(): Schema
    {
        return Schema::make()
            ->primaryKey('id')
            ->int('id')
            ->string('uuid')
            ->enum('type', array_column(TransactionType::cases(), 'value'))
            ->enum('status', array_column(TransactionStatus::cases(), 'value'))
            ->string('note')->nullable()
            ->datetime('reversed_at')->nullable()
            ->int('purchase_order_id')->nullable()->references('purchase_orders.id')
            ->int('sale_order_id')->nullable()->references('sale_orders.id')
            ->int('return_order_id')->nullable()->references('return_orders.id')
            ->int('stock_adjustment_id')->nullable()->references('stock_adjustments.id')
            ->int('transfer_order_id')->nullable()->references('transfer_orders.id')
            ->datetime('created_at')->nullable()
            ->datetime('updated_at')->nullable();
    }
}
