# Dashboard Widgets

Two widgets registered in `AdminPanelProvider`.

---

## InventoryStatsWidget

**Path:** `app/Filament/Widgets/InventoryStatsWidget.php`
**Extends:** `Filament\Widgets\StatsOverviewWidget`

Returns 4 stats from `getStats()`:

| Stat | Value | Color | Link |
|---|---|---|---|
| Total Products | `Product::count()` | default | — |
| Low Stock Items | `Inventorix::lowStockItems()->count()` | danger | LowStockAlerts page |
| Inventory Value | `Inventorix::totalValuation()` formatted as currency | success | — |
| Pending Orders | confirmed POs + confirmed SOs count | warning | — |

Pending Orders calculation:
```php
PurchaseOrder::where('status', PurchaseOrderStatus::Confirmed)->count()
+ SaleOrder::where('status', SaleOrderStatus::Confirmed)->count()
```

---

## RecentMovementsWidget

**Path:** `app/Filament/Widgets/RecentMovementsWidget.php`
**Extends:** `Filament\Widgets\TableWidget`
**Column span:** `protected int | string | array $columnSpan = 'full'`

Shows the last 10 stock movements ordered by `created_at DESC`. No pagination, no filters.

| Column | Notes |
|---|---|
| Product | stockable.name |
| Location | location.name |
| Type | badge: Add (green) / Deduct (red) |
| Quantity | |
| Date | created_at formatted |

---

## Registration

In `app/Providers/Filament/AdminPanelProvider.php`:

```php
->widgets([
    AccountWidget::class,
    InventoryStatsWidget::class,
    RecentMovementsWidget::class,
])
```
