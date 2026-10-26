# ABC Analysis

**Navigation Group:** Inventory
**Navigation Sort:** 17
**Type:** Custom Filament Page with table (`InteractsWithTable`)
**Path:** `app/Filament/Pages/AbcAnalysis.php`
**Icon:** `Heroicon::OutlinedChartPie`

## Purpose

Classifies products into A/B/C categories based on consumption value (quantity deducted x cost) using the Pareto principle. Class A = top 80% of total consumption value, B = next 15%, C = bottom 5%. Helps prioritize inventory management effort — A items get tighter controls, C items get simplified handling.

## Filters

| Filter | Type | Notes |
|---|---|---|
| Date From | DatePicker | required, default 365 days ago |
| Date Until | DatePicker | required, default today |
| Location | Select | optional |
| Category | Select | optional |

## Table Columns

| Column | Source | Notes |
|---|---|---|
| Rank | row number | sequential |
| Product | products.name | searchable |
| Brand | products.brand.name | |
| Consumption Qty | sum of Deduct movement quantities | numeric(3) |
| Avg Cost/Unit | from product.cost or avg(cost_per_unit) | numeric(3) |
| Consumption Value | consumption_qty x avg_cost | numeric(2), currency |
| Cumulative % | running total / grand total x 100 | numeric(1), suffix `%` |
| ABC Class | based on cumulative % | badge: A (success) / B (warning) / C (gray) |

ABC class assignment:
- **A:** cumulative % <= 80%
- **B:** cumulative % <= 95%
- **C:** cumulative % > 95%

## Default Sort

Consumption Value DESC (rank order). Sort is fixed — ABC classification requires this order.

## Data Source

`AbcAnalysis::computeAbc(array $options)` (static, shared with the exporter; options `from`, `until`, `location_id`, `category`):

```php
Product::query() // SoftDeletes scope applies
    ->join('inventorix_movements', /* stockable_id = products.id AND stockable_type = Product */)
    ->where('inventorix_movements.type', MovementType::Deduct)
    ->whereBetween('inventorix_movements.created_at', [$from, $until])
    ->when($locationId, ...)->when($categoryId, fn ($q) => $q->whereHas('categories', ...))
    ->groupBy('products.id', 'products.cost')
    ->selectRaw('products.id as product_id, products.cost as cost, SUM(quantity) as total_qty')
    ->toBase()->get();
```

Then in PHP: `consumption_value = total_qty × cost`, sort DESC, running cumulative % → class (A ≤ 80%, B ≤ 95%, else C).

## Implementation Notes

- The cumulative % needs every consumed product's value, so ranking can't be limited to the current page. It is one aggregate query returning plain rows (no product models), cached per request in `$abcCache`.
- The table query is restricted to the ranked product ids and ordered by rank (`CASE id ...`); columns read values from the cached ranking.
- The category filter restricts the ranking itself (grand total is over the filtered set).

## Export

Header action `ExportAction` → `App\Filament\Exports\AbcAnalysisExporter` (model `Product`), with the page's current filters passed as options. The exporter runs `computeAbc()` once per export job (cached on the exporter instance) and reads each row's values from it — no per-row queries.

Columns: # (rank), Product, Brand, Consumption Qty, Avg Cost/Unit, Consumption Value, Cumulative %, ABC Class.

## Performance

- Two data queries per render regardless of page size: the ranking aggregate and the paginated table query.
- Query count per render is constant (verified by a `countQueries` test); classification is covered by tests.
