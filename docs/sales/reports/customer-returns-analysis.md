# Customer Returns Analysis

**Navigation Group:** Sales
**Navigation Sort:** 5
**Type:** Custom Filament Page with table (`InteractsWithTable`)
**Path:** `app/Filament/Pages/CustomerReturnsAnalysis.php`
**Icon:** `Heroicon::OutlinedArrowUturnLeft`

## Purpose

Analyzes customer return patterns by product and customer. Shows return quantities, return rates relative to sales, and financial impact. Helps identify quality issues, problematic products, and return-heavy customers.

## Filters

| Filter | Type | Notes |
|---|---|---|
| Date From | DatePicker | optional |
| Date Until | DatePicker | optional |
| Customer | Select | optional, searchable |
| Product | Select | optional, searchable |

## Table Columns

| Column | Source | Notes |
|---|---|---|
| Product | products.name | searchable |
| Customer | customers.name | from refunded order's customer/sale_order |
| Qty Returned | sum of refunded_order_items.quantity | numeric(3) |
| Qty Sold (period) | sum of sale_order_items.quantity for same product | numeric(3) |
| Return Rate | (qty_returned / qty_sold) x 100 | numeric(1), suffix `%`, red if > 10% |
| Refund Amount | sum of (quantity x unit_cost) | numeric(2), currency |
| Return Date | refunded_orders.created_at | date format |

## Default Sort

Return Rate DESC (highest return rate first).

## Data Source

```php
// Returns aggregated by product
RefundedOrderItem::query()
    ->with(['refundedOrder.customer', 'product'])
    ->join('refunded_orders', 'refunded_orders.id', '=', 'refunded_order_items.refunded_order_id')
    ->whereIn('refunded_orders.status', [RefundedOrderStatus::Completed])
    ->when($dateFrom, fn ($q) => $q->where('refunded_orders.created_at', '>=', $dateFrom))
    ->when($dateUntil, fn ($q) => $q->where('refunded_orders.created_at', '<=', $dateUntil))
    ->when($customerId, fn ($q) => $q->where('refunded_orders.customer_id', $customerId))
    ->when($productId, fn ($q) => $q->where('refunded_order_items.product_id', $productId))
    ->selectRaw('
        refunded_order_items.product_id,
        SUM(refunded_order_items.quantity) as qty_returned,
        SUM(refunded_order_items.quantity * refunded_order_items.unit_cost) as refund_amount
    ')
    ->groupBy('refunded_order_items.product_id')
```

For `qty_sold` and return rate, run a parallel query on `sale_order_items` for the same period and join in PHP.

## Performance

- Two aggregation queries (returns + sales), merged in PHP
- Paginate 25 rows default
