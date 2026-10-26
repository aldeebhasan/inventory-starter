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
| Product | products.name | searchable, sortable |
| Supplier | suppliers.name via purchase_order | |
| PO # | purchase_orders.order_number | linked to ViewPurchaseOrder |
| Ordered Date | purchase_orders.ordered_at | date format, sortable |
| Unit Cost | purchase_order_items.unit_cost | numeric(3), sortable |
| Quantity | purchase_order_items.quantity | numeric(3) |
| Total Cost | unit_cost x quantity | numeric(2), computed, sortable (`ORDER BY unit_cost * quantity`) |
| Cost Trend | compared to previous PO for same product+supplier | icon: up (danger) / down (success) / flat (gray) |

## Default Sort

`purchase_order_items.id` DESC (newest lines first).

## Data Source

```php
PurchaseOrderItem::query()
    ->with(['purchaseOrder.supplier', 'product'])
    ->select('purchase_order_items.*')
    ->selectSub(previousUnitCostSubquery(), 'previous_unit_cost')
    ->whereHas('purchaseOrder', fn ($q) => $q->whereIn('status', [Confirmed, Received]))
// filters: date range / supplier → whereHas('purchaseOrder'), product → product_id
```

## Cost Trend Logic

`previous_unit_cost` is a correlated subquery in the table query. It holds the `unit_cost` of the latest earlier PO line (by `purchase_order_id` DESC) for the same product and the same supplier, where that PO is Confirmed/Received, not soft-deleted, and has `ordered_at` before the row's PO.

`PurchaseCostAnalysis::costTrend(PurchaseOrderItem $record)` returns `up` when the current cost is higher than the previous one, `down` when it is lower, and `flat` when they are equal or there is no previous PO. The table and the exporter both use it.

The page used to run one lookup query per row. That is gone.

## Export

Header `ExportAction` → `App\Filament\Exports\PurchaseCostAnalysisExporter` (model `PurchaseOrderItem`). Columns: Product, Supplier, PO #, Ordered Date, Unit Cost, Quantity, Total Cost, Previous Unit Cost, Trend. The export uses the table's filtered and sorted query.

## Performance

- One table query with the previous-cost subquery, plus eager loads (PO, supplier, product). The query count stays the same however many rows are shown (covered by a `countQueries` test).
- Paginate 25 rows default
