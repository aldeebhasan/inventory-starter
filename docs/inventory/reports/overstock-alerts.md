# Overstock Alerts

**Navigation Group:** Inventory
**Navigation Sort:** 15
**Type:** Custom Filament Page with table (`InteractsWithTable`)
**Path:** `app/Filament/Pages/OverstockAlerts.php`
**Icon:** `Heroicon::OutlinedExclamationCircle`

## Purpose

Shows all products above their maximum stock threshold. Helps prevent capital lock-up, storage waste, and identifies candidates for promotional pricing or redistribution to other locations.

## Filters

| Filter | Type | Notes |
|---|---|---|
| Location | Select | optional |
| Category | Select | optional |

## Table Columns

| Column | Source | Notes |
|---|---|---|
| Product | products.name | searchable |
| Brand | products.brand.name | |
| Location | inventorix_locations.name | |
| On Hand | inventorix_stocks.quantity | |
| Reserved | inventorix_stocks.reserved_quantity | |
| Available | quantity - reserved_quantity | computed |
| Max Threshold | inventorix_thresholds.max_quantity | |
| Surplus | available - max_threshold | warning badge |

## Default Sort

Surplus DESC (most overstocked first).

## Data Source

```php
Stock::query()
    ->with(['stockable.brand', 'location'])
    ->whereHasMorph('stockable', [Product::class])
    ->whereHas('thresholds', fn ($q) => $q->whereNotNull('max_quantity'))
    ->whereRaw('quantity - reserved_quantity > (
        SELECT max_quantity FROM inventorix_thresholds
        WHERE inventorix_thresholds.stockable_id = inventorix_stocks.stockable_id
        AND inventorix_thresholds.stockable_type = inventorix_stocks.stockable_type
        AND inventorix_thresholds.location_id = inventorix_stocks.location_id
    )')
```

Alternative: use `Inventorix::checkThresholds()` if it supports overstock detection, or query stocks joined with thresholds where `quantity > max_quantity`.

## Performance

- Single query with subquery join on thresholds
- Paginate 25 rows default
