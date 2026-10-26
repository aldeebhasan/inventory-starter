# Product Movement Summary

**Navigation Group:** Inventory
**Navigation Sort:** 11
**Type:** Custom Filament Page with table (`InteractsWithTable`)
**Path:** `app/Filament/Pages/ProductMovementSummary.php`
**Icon:** `Heroicon::OutlinedArrowTrendingUp`

## Purpose

Aggregated view of total inbound (Add) and outbound (Deduct) quantities per product over a selected period. Identifies fast-movers, slow-movers, and net stock change. Essential for demand planning and category management.

## Filters

| Filter | Type | Notes |
|---|---|---|
| Date From | DatePicker | required, default: 30 days ago |
| Date Until | DatePicker | required, default: today |
| Location | Select | optional |
| Category | Select | optional |
| Brand | Select | optional |

## Table Columns

| Column | Source | Notes |
|---|---|---|
| Product | products.name | searchable |
| Brand | products.brand.name | |
| Category | products.categories | first category |
| Total Added | sum of Add movement quantities | numeric(3) |
| Total Deducted | sum of Deduct movement quantities | numeric(3) |
| Net Change | total_added - total_deducted | green if positive, red if negative |
| Current Stock | inventorix_stocks.quantity | from current stock, not period-based |
| Turnover Rate | total_deducted / current_stock | numeric(2), shows `—` if no stock |

## Default Sort

Product name ASC.

## Data Source

`ProductMovementSummary::getReportQuery(string $from, string $until, ?int $locationId)` (static, shared with the exporter):

```php
Product::query()
    ->select('products.*')
    ->addSelect([
        'total_added'    => /* SUM(quantity) of Add movements in range (+ location) */,
        'total_deducted' => /* SUM(quantity) of Deduct movements in range (+ location) */,
        'current_stock'  => /* SUM(inventorix_stocks.quantity), all locations */,
    ])
    ->with(['brand', 'categories'])
    ->whereExists(/* any movement of this product in the date range (and location, if set) */);
```

Only products that actually moved in the selected date range (and at the selected location) are listed. The Date Range and Location filters feed this query (the table query is a closure rebuilt with current filter values); their own `query()` callbacks are intentionally pass-through.

## Export

Header action `ExportAction` → `App\Filament\Exports\ProductMovementSummaryExporter` (model `Product`). It exports the page's filtered/sorted query, so the aggregate subqueries are included — no per-row queries (`brand`, `categories` eager-loaded).

Columns: Product, Brand, Category, Total Added, Total Deducted, Net Change, Current Stock, Turnover Rate.

## Performance

- One paginated query; aggregates are correlated subqueries evaluated only for the returned page (previously movement and stock aggregates for every product were loaded on each render).
- Query count per render is constant (verified by a `countQueries` test).
