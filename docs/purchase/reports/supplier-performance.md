# Supplier Performance

**Navigation Group:** Purchase
**Navigation Sort:** 5
**Type:** Custom Filament Page with table (`InteractsWithTable`)
**Path:** `app/Filament/Pages/SupplierPerformance.php`
**Icon:** `Heroicon::OutlinedPresentationChartLine`

## Purpose

Evaluates suppliers on key delivery and cost metrics: order volume, fulfillment rate, lead time, total spend, and return rates. Helps purchasing managers identify the most reliable and cost-effective suppliers.

## Filters

| Filter | Type | Notes |
|---|---|---|
| Date From | DatePicker | optional |
| Date Until | DatePicker | optional |
| Supplier | Select | optional |

## Table Columns

| Column | Source | Notes |
|---|---|---|
| Supplier | suppliers.name | searchable, linked to ViewSupplier |
| Total POs | count of purchase_orders | |
| Received | count where status = Received | |
| Cancelled | count where status = Cancelled | |
| Fulfillment Rate | received / total x 100 | numeric(1), suffix `%` |
| Avg Lead Time | avg days between ordered_at and received_at | numeric(1), suffix ` days` |
| Total Items | sum of purchase_order_items.quantity | numeric(0) |
| Total Spend | sum of (quantity x unit_cost) from received POs | numeric(2), currency |
| Avg Unit Cost | total_spend / total_items | numeric(3) |

## Default Sort

Total Spend DESC (biggest suppliers first).

## Data Source

```php
Supplier::query()
    ->withCount([
        'purchaseOrders as total_pos' => fn ($q) => $q->when($dateFrom, ...),
        'purchaseOrders as received_pos' => fn ($q) => $q->where('status', PurchaseOrderStatus::Received),
        'purchaseOrders as cancelled_pos' => fn ($q) => $q->where('status', PurchaseOrderStatus::Cancelled),
    ])
    ->addSelect([
        'total_spend' => PurchaseOrderItem::query()
            ->selectRaw('SUM(quantity * unit_cost)')
            ->whereColumn('purchase_order_items.purchase_order_id', 'purchase_orders.id')
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
            ->where('purchase_orders.status', PurchaseOrderStatus::Received)
            ->whereColumn('purchase_orders.supplier_id', 'suppliers.id'),
        'avg_lead_time' => PurchaseOrder::query()
            ->selectRaw('AVG(DATEDIFF(updated_at, ordered_at))')
            ->whereColumn('supplier_id', 'suppliers.id')
            ->where('status', PurchaseOrderStatus::Received),
    ])
```

Alternative: build as a raw query with GROUP BY on supplier_id for better performance.

## Performance

- Complex aggregation; use subqueries or a single raw query with GROUP BY
- Supplier count is typically small (tens to low hundreds), so performance is not a concern
- Paginate 25 rows default
