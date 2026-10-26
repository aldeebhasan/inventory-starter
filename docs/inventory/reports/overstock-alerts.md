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
| Max Threshold | `max_threshold_quantity` selected subquery | location-specific threshold preferred over the global one |
| Surplus | max(0, available - max_threshold) | warning badge |

## Default Sort

On Hand (`quantity`) DESC.

## Header Actions

**Export** — `ExportAction` with `App\Filament\Exports\OverstockAlertsExporter` (CSV/XLSX, queued). Exports the current filtered table query. Columns: Product, Brand, Location, On Hand, Reserved, Available, Max Threshold, Surplus. `modifyQuery()` eager-loads `location` and `stockable` (Product → brand).

## Data Source

```php
Stock::query()
    ->select('inventorix_stocks.*')
    ->addSelect(['max_threshold_quantity' => /* correlated subquery: location threshold first, then global */])
    ->with(['stockable' => fn (MorphTo $m) => $m->morphWith([Product::class => ['brand']]), 'location'])
    ->whereHasMorph('stockable', [Product::class])
    ->where('quantity', '>', 0)
    ->whereRaw('quantity - reserved_quantity > (SELECT COALESCE(MAX(max_quantity), quantity + 1) FROM inventorix_thresholds WHERE <same stockable> AND (<same location> OR location_id IS NULL) AND max_quantity IS NOT NULL)')
```

## Performance

- The max threshold is selected with the page query; the page no longer preloads every threshold in the table into PHP
- Constant query count per page (4 queries for 2 or 8 rows)

## Tests

`tests/Feature/Inventory/OverstockAlertsPageTest.php` — render, listing, filters, location-over-global max threshold + surplus, query growth, export (fake dispatch, row values, real CSV).
