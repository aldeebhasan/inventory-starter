# Stock Report

**Navigation Group:** Reports
**Type:** Custom Filament Page with table (`InteractsWithTable`)
**Path:** `app/Filament/Pages/StockReport.php`
**Icon:** `Heroicon::OutlinedChartBar`

---

## Purpose

Shows current stock levels across all products and locations. Supports filtering by location, category, and low-stock status. Useful for inventory audits and reorder planning.

---

## Filters

| Filter | Type | Notes |
|---|---|---|
| Location | Select | optional, scopes to a single location |
| Category | Select | optional, scopes to products in that category |
| Low Stock Only | Toggle | shows only items below their min threshold |

---

## Table Columns

| Column | Source | Notes |
|---|---|---|
| Product | products.name | searchable |
| Category | categories.name | via product relationship |
| Location | inventorix_locations.name | |
| Qty On Hand | inventorix_stocks.quantity | |
| Reserved | inventorix_stocks.reserved_quantity | |
| Available | quantity − reserved_quantity | computed |
| Valuation | products.cost × available | computed |
| Status | isLowStock() | badge: Low Stock / OK |

---

## Data Source

Query `inventorix_stocks` joined with `products`, `categories`, and `inventorix_locations`, filtered by the form state.

```php
Stock::query()
    ->with(['stockable.category', 'location'])
    ->whereHasMorph('stockable', [Product::class])
    ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
    ->when($categoryId, fn ($q) => $q->whereHasMorph('stockable', [Product::class], fn ($p) => $p->where('category_id', $categoryId)))
    ->when($lowStockOnly, fn ($q) => $q->whereHas('thresholds', ...))
```

---

## Actions

- **Export** button → runs `php artisan inventorix:stock-report` via `Artisan::call()`
