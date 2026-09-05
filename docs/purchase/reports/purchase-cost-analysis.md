# Purchase Cost Analysis

**Navigation Group:** Purchase
**Navigation Sort:** 4
**Type:** Custom Filament Page with table (`InteractsWithTable`)
**Path:** `app/Filament/Pages/PurchaseCostAnalysis.php`
**Icon:** `Heroicon::OutlinedCalculator`

## Purpose

Tracks purchase costs over time by product and supplier. Identifies cost trends, price increases, and enables supplier comparison. Used by purchasing managers to negotiate better prices and by finance to understand cost-of-goods trends.

## Filters

| Filter | Type | Notes |
|---|---|---|
| Date From | DatePicker | optional |
| Date Until | DatePicker | optional |
| Product | Select | optional, searchable |
| Supplier | Select | optional |

## Table Columns

| Column | Source | Notes |
|---|---|---|
| Product | products.name | searchable |
| Supplier | suppliers.name via purchase_order | |
| PO # | purchase_orders.order_number | linked to ViewPurchaseOrder |
| Ordered Date | purchase_orders.ordered_at | date format |
| Unit Cost | purchase_order_items.unit_cost | numeric(3) |
| Quantity | purchase_order_items.quantity | numeric(3) |
| Total Cost | unit_cost x quantity | numeric(2), computed |
| Cost Trend | compared to previous PO for same product+supplier | icon: up (danger) / down (success) / flat (gray) |

## Default Sort

Ordered Date DESC (newest first).

## Data Source

```php
PurchaseOrderItem::query()
    ->with([
        'purchaseOrder.supplier',
        'product',
    ])
    ->whereHas('purchaseOrder', function ($q) use ($dateFrom, $dateUntil, $supplierId) {
        $q->whereIn('status', [
            PurchaseOrderStatus::Confirmed,
            PurchaseOrderStatus::Received,
        ]);
        if ($dateFrom) $q->where('ordered_at', '>=', $dateFrom);
        if ($dateUntil) $q->where('ordered_at', '<=', $dateUntil);
        if ($supplierId) $q->where('supplier_id', $supplierId);
    })
    ->when($productId, fn ($q) => $q->where('product_id', $productId))
```

## Cost Trend Logic

For each row, compare `unit_cost` with the previous PO item for the same product + supplier:

```php
// Using a window function or subquery:
$previousCost = PurchaseOrderItem::query()
    ->where('product_id', $item->product_id)
    ->whereHas('purchaseOrder', fn ($q) => $q
        ->where('supplier_id', $item->purchaseOrder->supplier_id)
        ->where('ordered_at', '<', $item->purchaseOrder->ordered_at))
    ->orderByDesc('purchase_order_id')
    ->value('unit_cost');

// Trend: up if current > previous, down if current < previous, flat if equal or no previous
```

For performance, batch this with a self-join or `LAG()` window function if the database supports it.

## Performance

- Single query with eager loading
- Cost trend can be computed via a subquery or post-processed in PHP
- Paginate 25 rows default
