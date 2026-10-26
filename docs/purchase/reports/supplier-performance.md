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
| Date From | DatePicker | optional; limits the list to suppliers with POs in range **and** limits every metric to POs whose `ordered_at` is in range |
| Date Until | DatePicker | optional; same as above |
| Supplier | Select | optional |

Before this change the date range only decided which suppliers were listed. Every metric still counted all-time POs.

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

The query is passed as a closure, so it is rebuilt on each request. `inDateRange()` applies the current date filter (`purchase_orders.ordered_at`) to every subquery.

```php
Supplier::query()
    ->withCount([
        'purchaseOrders as total_pos' => fn ($q) => $this->inDateRange($q),
        'purchaseOrders as received_pos' => fn ($q) => $this->inDateRange($q)->where('status', Received),
        'purchaseOrders as cancelled_pos' => fn ($q) => $this->inDateRange($q)->where('status', Cancelled),
    ])
    ->addSelect([
        'total_spend' => $this->inDateRange(PurchaseOrderItem::query())   // SUM(quantity * unit_cost), received POs
            ->join('purchase_orders', ...)->where('purchase_orders.status', Received)->whereColumn('purchase_orders.supplier_id', 'suppliers.id'),
        'total_items' => /* same, SUM(quantity) */,
        'avg_lead_time' => $this->inDateRange(PurchaseOrder::query())  // AVG(DATEDIFF(updated_at, ordered_at)); julianday on sqlite
            ->whereColumn('supplier_id', 'suppliers.id')->where('status', Received),
    ])
```

Sortable: Supplier, Total POs, Received, Cancelled, Total Spend.

## Export

Header `ExportAction` → `App\Filament\Exports\SupplierPerformanceExporter` (model `Supplier`). Columns: Supplier, Total POs, Received, Cancelled, Fulfillment Rate %, Avg Lead Time (days), Total Items, Total Spend, Avg Unit Cost. The export uses the table's filtered and sorted query, including the date-scoped subqueries.

## Performance

- One query with correlated count/sum subqueries, and no per-row queries. The query count stays the same however many rows are shown (covered by a `countQueries` test).
- Paginate 25 rows default
