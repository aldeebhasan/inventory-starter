# Sales Summary

**Navigation Group:** Sales
**Navigation Sort:** 4
**Type:** Custom Filament Page with table (`InteractsWithTable`)
**Path:** `app/Filament/Pages/SalesSummary.php`
**Icon:** `Heroicon::OutlinedChartBarSquare`

## Purpose

Aggregated view of sales performance by product over a selected period. Shows quantity sold, revenue, cost, and gross margin. Used by sales managers for performance tracking and by finance for profitability analysis.

## Filters

| Filter | Type | Notes |
|---|---|---|
| Date From | DatePicker | required, default 30 days ago |
| Date Until | DatePicker | required, default today |
| Product | Select | optional, searchable |
| Customer | Select | optional, searchable |
| Category | Select | optional |

## Table Columns

| Column | Source | Notes |
|---|---|---|
| Product | products.name | searchable |
| Brand | products.brand.name | |
| Category | products.categories | first category |
| Qty Sold | sum of sale_order_items.quantity | numeric(3) |
| Avg Unit Price | avg of sale_order_items.unit_price | numeric(3) |
| Total Revenue | sum of (quantity x unit_price) | numeric(2), currency |
| Total Cost | sum of (quantity x product.cost) | numeric(2), currency |
| Gross Margin | revenue - cost | numeric(2), currency |
| Margin % | (margin / revenue) x 100 | numeric(1), suffix `%`, green if > 20%, red if < 0% |

## Default Sort

Total Revenue DESC (top sellers first).

## Data Source

The table is based on `Product` (grouped by `products.id`), so the record key is the product. Rows can then be resolved by key, and the queued export can chunk them by key.

```php
Product::withTrashed()
    ->with(['brand', 'categories'])   // eager-loaded for the current page (no per-row Product::find)
    ->join('sale_order_items', 'sale_order_items.product_id', '=', 'products.id')
    ->join('sale_orders', 'sale_orders.id', '=', 'sale_order_items.sale_order_id')
    ->whereIn('sale_orders.status', [Confirmed, Shipped, Picked, Fulfilled])
    ->select('products.*')
    ->selectRaw('
        SUM(sale_order_items.quantity) as qty_sold,
        AVG(sale_order_items.unit_price) as avg_unit_price,
        SUM(sale_order_items.quantity * sale_order_items.unit_price) as total_revenue,
        SUM(sale_order_items.quantity * products.cost) as total_cost
    ')
    ->groupBy('products.id')
```

Filters: date range → `sale_orders.created_at`; product → `products.id`; customer → `sale_orders.customer_id`; category → `whereHas('categories')`. The category filter used to pluck every matching product ID into PHP; it no longer does.

Margin computed in PHP: `total_revenue - total_cost`.

Sortable: Qty Sold, Avg Unit Price, Total Revenue, Total Cost (SQL aliases).

## Export

Header `ExportAction` → `App\Filament\Exports\SalesSummaryExporter` (model `Product`). Columns: Product, Brand, Category (first), Qty Sold, Avg Unit Price, Total Revenue, Total Cost, Gross Margin, Margin %. The export uses the table's filtered and sorted query.

## Stats Header (optional)

Display summary stats above the table:

| Stat | Value |
|---|---|
| Total Revenue | sum of all rows' revenue |
| Total Cost | sum of all rows' cost |
| Gross Margin | total revenue - total cost |
| Orders Count | distinct sale_order count in period |

## Performance

- Single aggregation query with GROUP BY, plus one eager-load query each for brands and categories. The query count stays the same however many rows are shown (covered by a `countQueries` test).
- Paginate 25 rows default
