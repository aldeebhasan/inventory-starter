# Low Stock Alerts

**Navigation Group:** Reports
**Type:** Custom Filament Page with table (`InteractsWithTable`)
**Path:** `app/Filament/Pages/LowStockAlerts.php`
**Icon:** `Heroicon::OutlinedExclamationTriangle`

---

## Purpose

Shows all products that have fallen at or below their minimum stock threshold. Supports quick creation of a Purchase Order directly from the alert.

---

## Navigation Badge

The nav item shows a live count of low-stock items:

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

---

## Filters

| Filter | Type | Notes |
|---|---|---|
| Location | Select | optional |
| Category | Select | optional, scopes by product category |

---

## Table Columns

| Column | Notes |
|---|---|
| Product | link to ProductResource view page |
| Category | products.category.name |
| Location | inventorix_locations.name |
| On Hand | inventorix_stocks.quantity |
| Reserved | inventorix_stocks.reserved_quantity |
| Available | on_hand − reserved |
| Min Threshold | inventorix_thresholds.min_quantity |
| Deficit | threshold − available, red badge if positive |

---

## Table Actions

**Create Purchase Order** — opens `CreatePurchaseOrder` page with the product pre-selected (or via a quick modal if feasible).

---

## Data Source

```php
Inventorix::lowStockItems(
    stockableType: Product::class,
    locationId: $locationId ?? null,
)
```
