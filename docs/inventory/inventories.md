# Inventories

**Navigation Group:** Inventory
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

## Table Columns

| Column | Source | Notes |
|---|---|---|
| Product | `stockable.name` | searchable via `whereHasMorph` |
| Brand | `stockable.brand.name` | shows `—` if none |
| Location | `inventorix_locations.name` | sortable |
| On Hand | `inventorix_stocks.quantity` | sortable |
| Reserved | `inventorix_stocks.reserved_quantity` | sortable |
| Available | `quantity − reserved_quantity` | computed |
| Min Threshold | `inventorix_thresholds.min_quantity` | scoped to product + location; shows `—` if unset |
| Max Threshold | `inventorix_thresholds.max_quantity` | shows `—` if unset |
| Status | computed badge | `Low Stock` (danger) / `OK` (success) / `No Threshold` (gray) |

## Row Actions

### Set Threshold

- Opens a modal with `min_quantity` (required) and `max_quantity` (optional) inputs.
- Pre-fills from existing `inventorix_thresholds` record for the product + location.
- Calls `$product->setStockThreshold(min, max, locationId)` on save — creates or updates via `updateOrCreate`.

## Data Source

```php
Stock::query()
    ->with(['stockable.brand', 'stockable.categories', 'location'])
    ->whereHasMorph('stockable', [Product::class])
```

## Tests

`tests/Feature/InventoriesPageTest.php`

- Renders successfully
- Lists stock records
- Filters by location
- Filters by product
- Sets a new threshold
- Updates an existing threshold
- Validates `min_quantity` required
