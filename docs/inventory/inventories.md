# Stocks (Inventories)

**Navigation Group:** Inventory
**Navigation Label:** Stocks
**Type:** Custom Filament Page with table (`InteractsWithTable`)
**Path:** `app/Filament/Pages/Inventories.php`
**Icon:** `Heroicon::OutlinedArchiveBox`

## Purpose

Shows all current stock levels for inventory products across all locations. Allows operators to review quantities and define min/max thresholds per product-location combination.

## Filters

| Filter | Type | Notes |
|---|---|---|
| Location | Select | filter by `location_id`; options from active locations |
| Product | Select | filter by `stockable_id` (scoped to `Product` morphable type) |
| Category | Select | products in the category (`whereHasMorph` + `categories`) |
| Low Stock Only | Ternary | **Yes** = stocks whose `quantity <= min_quantity` of a location or global threshold (`whereExists`); **No** = the complement (`whereNotExists`). Previously **No** was a no-op. |

## Table Columns

| Column | Source | Notes |
|---|---|---|
| Product | `stockable.name` | searchable via `whereHasMorph`; sortable via a `products.name` subquery (the default morphTo relationship sort produced invalid SQL) |
| Brand | `stockable.brand.name` | shows `—` if none |
| Categories | `stockable.categories` | toggleable, hidden by default |
| Location | `inventorix_locations.name` | sortable |
| On Hand | `inventorix_stocks.quantity` | sortable |
| Reserved | `inventorix_stocks.reserved_quantity` | sortable |
| Available | `quantity − reserved_quantity` | computed |
| Valuation | `available × products.cost` | toggleable, hidden by default |
| Min Threshold | `threshold_min_quantity` (selected subquery) | location-specific threshold; shows `—` if unset |
| Max Threshold | `threshold_max_quantity` (selected subquery) | shows `—` if unset |
| Status | computed badge | `Low Stock` (danger) / `OK` (success) / `No Threshold` (gray) |

## Row Actions

### Set Threshold

- Opens a modal with `min_quantity` (required) and `max_quantity` (optional) inputs.
- Pre-fills from existing `inventorix_thresholds` record for the product + location.
- Calls `$product->setStockThreshold(min, max, locationId)` on save — creates or updates via `updateOrCreate`.

## Header Actions

**Export** — `ExportAction` with `App\Filament\Exports\InventoriesExporter` (CSV/XLSX, queued, database notification on completion). Exports the current filtered/sorted table query. Columns: Product, Brand, Categories, Location, On Hand, Reserved, Available, Valuation, Min Threshold, Max Threshold, Status. `modifyQuery()` eager-loads `location` and `stockable` (`morphWith` Product → brand, categories).

## Data Source

```php
Stock::query()
    ->select('inventorix_stocks.*')
    ->addSelect([
        'threshold_min_quantity' => /* correlated subquery on inventorix_thresholds */,
        'threshold_max_quantity' => /* correlated subquery on inventorix_thresholds */,
    ])
    ->with(['stockable' => fn (MorphTo $m) => $m->morphWith([Product::class => ['brand', 'categories']]), 'location'])
    ->whereHasMorph('stockable', [Product::class])
```

No per-row queries: thresholds are selected with the page query (previously 4 `Threshold` queries per row), product/brand/categories/location are eager-loaded. The page query count is constant regardless of rows (5 queries for a page of 2 or 8 rows; was 13 → 29).

## Tests

`tests/Feature/InventoriesPageTest.php`

- Renders successfully
- Lists stock records
- Filters by location
- Filters by product
- Sets a new threshold
- Updates an existing threshold
- Validates `min_quantity` required
- Query count does not grow with rows (page render and real export)
- Threshold/status columns, product-name sort, Low Stock Only = No filter
- Export: fake dispatch, exporter row values, real CSV export
