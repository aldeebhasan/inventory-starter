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

Consumption Value DESC (highest value first). Sort is fixed — ABC classification requires this order.

## Data Source

```php
// Step 1: Aggregate consumption by product
$consumption = Movement::query()
    ->whereHasMorph('stockable', [Product::class])
    ->where('type', MovementType::Deduct)
    ->whereBetween('created_at', [$dateFrom, $dateUntil])
    ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
    ->selectRaw('stockable_id, SUM(quantity) as total_qty')
    ->groupBy('stockable_id')
    ->orderByDesc('total_qty')
    ->get();

// Step 2: Join with products, compute value, calculate cumulative %
// For each row: consumption_value = total_qty * product.cost
// Sort by consumption_value DESC
// Running cumulative sum / grand total → assign class
```

## Implementation Notes

- ABC classification is computed in PHP after the SQL aggregation, since it requires a running total
- The table is not a standard Eloquent query — use a custom `getTableQuery()` or build the data in `getTableRecords()`
- Pagination is optional since this is typically a full-product view, but use it for large catalogs
- Consider caching the result for the selected period since it's expensive and doesn't change intra-day

## Performance

- Single aggregation query
- Classification computed in PHP (running sum)
- Paginate 50 rows default (users often want to see full ranking)
