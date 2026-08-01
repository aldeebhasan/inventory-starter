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
    return (string) Inventorix::lowStockItems(stockableType: Product::class)->count() ?: null;
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
| Product | link to ProductResource view page |
| Brand | products.brand.name |
| Location | inventorix_locations.name |
| On Hand | inventorix_stocks.quantity |
| Reserved | inventorix_stocks.reserved_quantity |
| Available | on_hand − reserved |
| Min Threshold | inventorix_thresholds.min_quantity |
| Deficit | threshold − available, red badge if positive |

## Table Actions

**Create Purchase Order** — redirects to `CreatePurchaseOrder` with the product pre-selected.

## Data Source

```php
Inventorix::lowStockItems(
    stockableType: Product::class,
    locationId: $locationId ?? null,
)
```
