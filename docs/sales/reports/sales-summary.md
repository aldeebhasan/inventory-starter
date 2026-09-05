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

```php
SaleOrderItem::query()
    ->join('sale_orders', 'sale_orders.id', '=', 'sale_order_items.sale_order_id')
    ->join('products', 'products.id', '=', 'sale_order_items.product_id')
    ->whereIn('sale_orders.status', [
        SaleOrderStatus::Shipped,    // completed sales
        SaleOrderStatus::Picked,     // dispatched sales
    ])
    ->whereBetween('sale_orders.created_at', [$dateFrom, $dateUntil])
    ->when($productId, fn ($q) => $q->where('product_id', $productId))
    ->when($customerId, fn ($q) => $q->where('sale_orders.customer_id', $customerId))
    ->selectRaw('
        sale_order_items.product_id,
        SUM(sale_order_items.quantity) as qty_sold,
        AVG(sale_order_items.unit_price) as avg_unit_price,
        SUM(sale_order_items.quantity * sale_order_items.unit_price) as total_revenue,
        SUM(sale_order_items.quantity * products.cost) as total_cost
    ')
    ->groupBy('sale_order_items.product_id')
```

Margin computed in PHP: `total_revenue - total_cost`.

## Stats Header (optional)

Display summary stats above the table:

| Stat | Value |
|---|---|
| Total Revenue | sum of all rows' revenue |
| Total Cost | sum of all rows' cost |
| Gross Margin | total revenue - total cost |
| Orders Count | distinct sale_order count in period |

## Performance

- Single aggregation query with GROUP BY
- Paginate 25 rows default
