# Dead Stock Report

**Navigation Group:** Inventory
**Navigation Sort:** 14
**Type:** Custom Filament Page with table (`InteractsWithTable`)
**Path:** `app/Filament/Pages/DeadStockReport.php`
**Icon:** `Heroicon::OutlinedArchiveBoxXMark`

## Purpose

Identifies products with stock on hand but zero or near-zero movement over a configurable period. Candidates for clearance sales, write-offs, or return to supplier. Helps free up capital and warehouse space.

## Filters

| Filter | Type | Notes |
|---|---|---|
| Min Days Inactive | Select | 30 / 60 / 90 / 180 / 365, default 90 |
| Location | Select | optional |
| Category | Select | optional |
| Brand | Select | optional |

## Table Columns

| Column | Source | Notes |
|---|---|---|
| Product | products.name | searchable |
| Brand | products.brand.name | |
| Location | inventorix_locations.name | |
| Qty On Hand | inventorix_stocks.quantity | |
| Last Movement Date | MAX(movements.created_at) | date format, or `Never` if null |
| Days Since Last Movement | DATEDIFF(now, last_movement) | integer |
| Stock Value | quantity x product.cost | numeric(2), currency |

## Default Sort

Days Since Last Movement DESC (longest idle first).

## Data Source

```php
// Stocks with quantity > 0 that have no deduct movements in the threshold period
Stock::query()
    ->with(['stockable.brand', 'location'])
    ->whereHasMorph('stockable', [Product::class])
    ->where('quantity', '>', 0)
    ->addSelect([
        'last_movement_at' => Movement::query()
            ->whereColumn('stockable_id', 'inventorix_stocks.stockable_id')
            ->whereColumn('stockable_type', 'inventorix_stocks.stockable_type')
            ->whereColumn('location_id', 'inventorix_stocks.location_id')
            ->where('type', MovementType::Deduct)
            ->selectRaw('MAX(created_at)')
            ->limit(1),
    ])
    ->having('last_movement_at', '<', now()->subDays($minDaysInactive))
    ->orHavingNull('last_movement_at')
```

Alternative approach using a raw join:
```sql
SELECT s.*, MAX(m.created_at) as last_deduct_at
FROM inventorix_stocks s
LEFT JOIN inventorix_movements m
    ON m.stockable_id = s.stockable_id
    AND m.stockable_type = s.stockable_type
    AND m.location_id = s.location_id
    AND m.type = 'Deduct'
WHERE s.quantity > 0
GROUP BY s.id
HAVING last_deduct_at < DATE_SUB(NOW(), INTERVAL ? DAY)
    OR last_deduct_at IS NULL
```

## Performance

- Single query with subquery or LEFT JOIN
- Paginate 25 rows default
