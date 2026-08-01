# Stock Report

**Navigation Group:** Inventory
**Type:** Custom Filament Page with table (`InteractsWithTable`)
**Path:** `app/Filament/Pages/StockReport.php`
**Icon:** `Heroicon::OutlinedChartBar`

## Purpose

Shows current stock levels across all products and locations. Useful for inventory audits and reorder planning.

## Filters

| Filter | Type | Notes |
|---|---|---|
| Location | Select | optional |
| Category | Select | optional |
| Low Stock Only | Toggle | shows only items below min threshold |

## Table Columns

| Column | Source | Notes |
|---|---|---|
| Product | products.name | searchable |
| Brand | products.brand.name | |
| Categories | via pivot | comma-separated |
| Location | inventorix_locations.name | |
| Qty On Hand | inventorix_stocks.quantity | |
| Reserved | inventorix_stocks.reserved_quantity | |
| Available | quantity − reserved_quantity | computed |
| Valuation | products.cost × available | computed |
| Status | isLowStock() | badge: Low Stock / OK |

## Data Source

```php
Stock::query()
    ->with(['stockable.brand', 'stockable.categories', 'location'])
    ->whereHasMorph('stockable', [Product::class])
    ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
    ->when($categoryId, fn ($q) => $q->whereHasMorph('stockable', [Product::class],
        fn ($p) => $p->whereHas('categories', fn ($c) => $c->where('categories.id', $categoryId))
    ))
    ->when($lowStockOnly, fn ($q) => $q->whereHas('thresholds', ...))
```

## Actions

Export button → runs `php artisan inventorix:stock-report` via `Artisan::call()`
