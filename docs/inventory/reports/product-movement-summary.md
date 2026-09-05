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

Total Deducted DESC (fastest movers first).

## Data Source

Single aggregation query:

```php
$movements = Movement::query()
    ->whereHasMorph('stockable', [Product::class])
    ->whereBetween('created_at', [$dateFrom, $dateUntil])
    ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
    ->selectRaw('
        stockable_id,
        SUM(CASE WHEN type = ? THEN quantity ELSE 0 END) as total_added,
        SUM(CASE WHEN type = ? THEN quantity ELSE 0 END) as total_deducted
    ', [MovementType::Add->value, MovementType::Deduct->value])
    ->groupBy('stockable_id')
    ->get();
```

Join with `Product::with(['brand', 'categories'])` and current `Stock` for the current stock column.

## Performance

- Single aggregation query with GROUP BY
- Category/brand filters applied via subquery on stockable_id
- Paginate 25 rows default
