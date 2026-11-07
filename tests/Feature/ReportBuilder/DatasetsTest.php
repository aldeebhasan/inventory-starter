<?php

use Aldeebhasan\Inventorix\DTOs\StockOperationDto;
use Aldeebhasan\Inventorix\Enums\TransactionType;
use Aldeebhasan\Panorama\Facades\Datasets;
use Aldeebhasan\Panorama\Query\Aggregate;
use App\Models\Addon;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\PurchaseOrderItem;
use App\Models\ReturnOrderItem;
use App\Models\SaleOrder;
use App\Models\SaleOrderItem;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentItem;
use App\Models\Supplier;
use App\Models\TransferOrderItem;
use App\Models\Unit;
use Illuminate\Support\Carbon;

function datasetRows(string $name, array $params = []): array
{
    return Datasets::from($name)->params($params)->get();
}

it('registers every dataset by discovery', function () {
    expect(Datasets::names())->toEqualCanonicalizing([
        'addons', 'brands', 'categories', 'customers', 'locations', 'product_categories', 'products',
        'purchase_order_items', 'purchase_orders', 'reservations', 'return_order_items', 'return_orders',
        'sale_order_items', 'sale_orders', 'stock_adjustment_items', 'stock_adjustments', 'stock_movements',
        'stock_transactions', 'stocks', 'suppliers', 'transfer_order_items', 'transfer_orders', 'units',
    ]);
});

it('reads factory rows of every dataset within its schema', function () {
    $product = Product::factory()->create([
        'brand_id' => Brand::factory()->create()->id,
        'unit_id' => Unit::factory()->create()->id,
    ]);
    $product->categories()->attach(Category::create(['name' => 'Tools']));
    Addon::factory()->create();
    Supplier::factory()->create();
    SaleOrderItem::factory()->create(['product_id' => $product->id]);
    PurchaseOrderItem::factory()->create(['product_id' => $product->id]);
    ReturnOrderItem::factory()->create(['product_id' => $product->id]);
    TransferOrderItem::factory()->create(['product_id' => $product->id]);
    StockAdjustmentItem::factory()->create(['product_id' => $product->id]);
    $location = createLocation();
    $product->addStock(5, $location);
    $product->reserve(2, $location);

    foreach (Datasets::names() as $name) {
        expect(datasetRows($name))->not->toBeEmpty("{$name} returned no rows");
    }
});

it('narrows transactional datasets by the from/to parameters', function () {
    Carbon::setTestNow('2026-01-10 12:00:00');
    $old = SaleOrder::factory()->create();
    Carbon::setTestNow('2026-03-10 23:59:00');
    $inRange = SaleOrder::factory()->create();
    Carbon::setTestNow();

    $ids = array_column(datasetRows('sale_orders', ['from' => '2026-03-01', 'to' => '2026-03-10']), 'id');

    expect($ids)->toBe([$inRange->id])->not->toContain($old->id);
});

it('does not date-filter master data', function () {
    Carbon::setTestNow('2020-01-01');
    Product::factory()->create();
    Carbon::setTestNow();

    expect(datasetRows('products', ['from' => '2026-01-01', 'to' => '2026-01-31']))->toHaveCount(1);
});

it('includes soft-deleted rows flagged as deleted', function () {
    $kept = Product::factory()->create();
    $deleted = Product::factory()->create();
    $deleted->delete();

    $flags = collect(datasetRows('products'))->pluck('is_deleted', 'id');

    expect($flags[$kept->id])->toBeFalse()
        ->and($flags[$deleted->id])->toBeTrue();
});

it('exposes polymorphic links as typed, joinable ids', function () {
    $item = StockAdjustmentItem::factory()->create();
    $adjustment = StockAdjustment::find($item->stock_adjustment_id);
    $location = createLocation();

    $item->product->addStock(3, $location, new StockOperationDto(
        transactionType: TransactionType::Adjustment,
        causable: $adjustment,
        reference: $item,
    ));

    $movement = datasetRows('stock_movements')[0];
    $transaction = datasetRows('stock_transactions')[0];
    $stock = datasetRows('stocks')[0];

    expect($movement['stock_adjustment_item_id'])->toBe($item->id)
        ->and($movement['sale_order_item_id'])->toBeNull()
        ->and($movement['product_id'])->toBe($item->product_id)
        ->and($transaction['stock_adjustment_id'])->toBe($adjustment->id)
        ->and($transaction['sale_order_id'])->toBeNull()
        ->and($stock['product_id'])->toBe($item->product_id)
        ->and($stock['location_id'])->toBe($location->id);

    $joined = Datasets::from('stock_movements')
        ->join('stock_adjustment_items', 'stock_movements.stock_adjustment_item_id', 'stock_adjustment_items.id')
        ->select('stock_movements.id', 'stock_adjustment_items.operation')
        ->get();

    expect($joined)->toHaveCount(1);
});

it('reads the location type from its meta column', function () {
    createLocation(['meta' => ['type' => 'warehouse']]);

    expect(datasetRows('locations')[0]['type'])->toBe('warehouse');
});

it('joins one dataset twice under aliases', function () {
    $item = TransferOrderItem::factory()->create();

    $rows = Datasets::from('transfer_orders')
        ->join('locations as from_location', 'transfer_orders.from_location_id', 'from_location.id')
        ->join('locations as to_location', 'transfer_orders.to_location_id', 'to_location.id')
        ->select('transfer_orders.id', 'from_location.name as source', 'to_location.name as destination')
        ->get();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['id'])->toBe($item->transfer_order_id)
        ->and($rows[0]['source'])->not->toBe($rows[0]['destination']);
});

it('adds day, week, month and year buckets to every date field', function () {
    Carbon::setTestNow('2026-01-05 09:30:00');
    SaleOrder::factory()->create(['shipped_at' => null]);
    Carbon::setTestNow();

    $row = datasetRows('sale_orders')[0];

    expect($row['created_at__day'])->toBe('2026-01-05')
        ->and($row['created_at__week'])->toBe('2026-W02')
        ->and($row['created_at__month'])->toBe('2026-01')
        ->and($row['created_at__year'])->toBe('2026')
        ->and($row['shipped_at__month'])->toBeNull();
});

it('groups by a time bucket', function () {
    Carbon::setTestNow('2026-01-10');
    SaleOrder::factory()->count(2)->create();
    Carbon::setTestNow('2026-02-10');
    SaleOrder::factory()->create();
    Carbon::setTestNow();

    $rows = Datasets::from('sale_orders')
        ->groupBy('created_at__month', ['orders' => Aggregate::count()])
        ->orderBy('created_at__month')
        ->get();

    expect($rows)->toBe([
        ['created_at__month' => '2026-01', 'orders' => 2],
        ['created_at__month' => '2026-02', 'orders' => 1],
    ]);
});
