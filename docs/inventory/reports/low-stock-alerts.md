# Low Stock Alerts

**Navigation Group:** Inventory
**Type:** Custom Filament Page with table (`InteractsWithTable`)
**Path:** `app/Filament/Pages/LowStockAlerts.php`
**Icon:** `Heroicon::OutlinedExclamationTriangle`

## Purpose

Shows all products at or below their minimum stock threshold. Supports quick creation of a Purchase Order directly from the alert.

## Navigation Badge

```php
public static function getNavigationBadge(): ?string
{
    return (string) static::lowStockQuery()->count() ?: null;
}

public static function getNavigationBadgeColor(): string
{
    return 'danger';
}
```

## Filters

| Filter | Type | Notes |
|---|---|---|
| Location | Select | optional |
| Category | Select | optional |

## Table Columns

| Column | Notes |
|---|---|
| Product | link to ProductResource view page; sortable via a `products.name` subquery |
| Brand | products.brand.name |
| Location | inventorix_locations.name |
| On Hand | inventorix_stocks.quantity |
| Reserved | inventorix_stocks.reserved_quantity |
| Available | on_hand − reserved |
| Min Threshold | `min_threshold_quantity` selected subquery — location-specific threshold preferred over the global one |
| Deficit | max(0, threshold − available), red badge if positive |

## Table Actions

**Create Purchase Order** — redirects to `CreatePurchaseOrder` with the product pre-selected.

## Header Actions

**Export** — `ExportAction` with `App\Filament\Exports\LowStockAlertsExporter` (CSV/XLSX, queued). Exports the current filtered/sorted table query. Columns: Product, Brand, Location, On Hand, Reserved, Available, Min Threshold, Deficit. `modifyQuery()` eager-loads `location` and `stockable` (Product → brand).

## Data Source

`LowStockAlerts::lowStockQuery()` (also used by the navigation badge) — pure SQL, nothing preloaded:

```php
Stock::query()
    ->where('inventorix_stocks.stockable_type', Product morph class)
    ->whereExists(/* threshold for same stockable, same location or global (NULL), with stocks.quantity <= min_quantity */)
```

The table adds `select('inventorix_stocks.*')`, the `min_threshold_quantity` subquery, and eager-loads `stockable` (Product → brand) and `location`.

Previously the page loaded every low-stock row via `Inventorix::lowStockItems()` and filtered with `whereIn(ids)`, then ran a `Threshold` query 3 times per row. Now the query count is constant (4 queries for 2 or 8 rows; was 12 → 24).

## Tests

`tests/Feature/Inventory/LowStockAlertsPageTest.php` — render, listing, filters, badge (count from the SQL query), location-over-global threshold + deficit, product-name sort, query growth, export (fake dispatch, row values, real CSV).
