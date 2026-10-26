# Customer Returns Analysis

**Navigation Group:** Sales
**Navigation Sort:** 5
**Type:** Custom Filament Page with table (`InteractsWithTable`)
**Path:** `app/Filament/Pages/CustomerReturnsAnalysis.php`
**Icon:** `Heroicon::OutlinedArrowUturnLeft`

## Purpose

Analyzes customer return patterns by product. Shows return quantities, return rates relative to sales, and financial impact. Helps identify quality issues and problematic products.

## Filters

| Filter | Type | Notes |
|---|---|---|
| Date From / Until | DatePicker | optional; filters `return_orders.created_at` **and** the period used for Qty Sold (`sale_orders.created_at`) |
| Customer | Select | optional, searchable (`return_orders.customer_id`) |
| Product | Select | optional, searchable (`products.id`) |

## Table Columns

| Column | Source | Notes |
|---|---|---|
| Product | products.name | searchable |
| Qty Returned | sum of return_order_items.quantity | numeric(3), sortable |
| Qty Sold (Period) | correlated sum of shipped/picked sale_order_items.quantity in the date range | numeric(3), sortable |
| Return Rate | (qty_returned / qty_sold) x 100 | badge, `—` when nothing sold, red if > 10% |
| Refund Amount | sum of (quantity x price) | currency, sortable |

## Default Sort

Qty Returned DESC.

## Data Source

The table is based on `Product` (grouped by `products.id`), so the record key is the product. Rows can then be resolved and exported by key. The query is passed as a closure, so the qty_sold subquery always uses the current date filter.

```php
Product::withTrashed()
    ->join('return_order_items', 'return_order_items.product_id', '=', 'products.id')
    ->join('return_orders', 'return_orders.id', '=', 'return_order_items.return_order_id')
    ->where('return_orders.type', ReturnOrderType::CustomerReturn)
    ->where('return_orders.status', ReturnOrderStatus::Completed)
    ->select('products.*')
    ->selectRaw('SUM(return_order_items.quantity) as qty_returned,
                 SUM(return_order_items.quantity * COALESCE(return_order_items.price, 0)) as refund_amount')
    ->selectSub($qtySoldSubquery, 'qty_sold')   // SUM over sale_order_items for products.id, Shipped/Picked, date range
    ->groupBy('products.id')
```

The page used to load sales aggregates for every product into a PHP cache and call `Product::find()` for each row. Both are gone. Qty sold is now a correlated subquery, so it is only evaluated for the rows SQL returns.

`CustomerReturnsAnalysis::returnRate(Product $record): ?float` is shared by the table and the exporter.

## Export

Header `ExportAction` → `App\Filament\Exports\CustomerReturnsAnalysisExporter` (model `Product`). Columns: Product, Qty Returned, Qty Sold (Period), Return Rate %, Refund Amount. The export uses the table's filtered and sorted query.

## Performance

- One aggregation query with a correlated qty_sold subquery. The query count stays the same however many rows are shown (covered by a `countQueries` test).
- Paginate 25 rows default
