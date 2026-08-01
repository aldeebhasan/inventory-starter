# Inventory Management System — Architecture

## Overview

A production-grade inventory management system built on Laravel 13 + Filament v5. The `aldeebhasan/inventorix` package handles **all** inventory logic. The application layer is responsible for business documents and the Filament UI only.

---

## Navigation Structure

| Group | Module |
|---|---|
| Catalog | [Categories, Brands, Units, Products, Addons](catalog.md) |
| Sales | [Customers](sales/customers.md), [Sale Orders](sales/sale-orders.md), [Customer Returns](sales/customer-returns.md) |
| Purchase | [Suppliers](purchase/suppliers.md), [Purchase Orders](purchase/purchase-orders.md), [Supplier Returns](purchase/supplier-returns.md) |
| Inventory | [Locations](inventory/locations.md), [Transfer Orders](inventory/transfer-orders.md), [Adjustment Orders](inventory/adjustment-orders.md), [Stock Report](inventory/stock-report.md), [Movement History](inventory/movement-history.md), [Low Stock Alerts](inventory/low-stock-alerts.md) |

See also: [Dashboard Widgets](dashboard/widgets.md)

---

## Key Architectural Decisions

1. **No custom inventory logic.** All stock operations delegate to the `aldeebhasan/inventorix` package via `HasInventory` trait methods and `StockOperationDto`.

2. **Location model wraps the package.** `App\Models\Location` extends `Aldeebhasan\Inventorix\Models\Location` — no separate migration needed.

3. **`Inventorix::bulk()` for all multi-item operations.** Every document that affects multiple line items wraps its inventory calls in a single bulk transaction, enabling atomic rollback.

4. **Reservation lifecycle on Sale Orders.** The `reservation_id` is stored on `sale_order_items` to track the full reserve → fulfill / release cycle.

5. **Workflow actions on View pages.** Status transitions (Confirm, Receive, Ship, etc.) are header actions on the `ViewXxx` Filament page — not inline table actions.

6. **Conventions.** Resources follow the `app/Filament/Resources/{PluralModel}/Pages|Schemas|Tables/` structure. Actions use `Filament\Actions\`. Icons use the `Heroicon` enum.

---

## Implementation Milestones

| # | Milestone | Docs |
|---|---|---|
| 1 | Enums, Brands, Addons, Location, Supplier, Customer | [catalog.md](catalog.md), [inventory/locations.md](inventory/locations.md), [purchase/suppliers.md](purchase/suppliers.md), [sales/customers.md](sales/customers.md) |
| 2 | Purchase Orders | [purchase/purchase-orders.md](purchase/purchase-orders.md) |
| 3 | Sale Orders | [sales/sale-orders.md](sales/sale-orders.md) |
| 4 | Transfer Orders | [inventory/transfer-orders.md](inventory/transfer-orders.md) |
| 5 | Adjustment Orders | [inventory/adjustment-orders.md](inventory/adjustment-orders.md) |
| 6 | Return Orders | [sales/customer-returns.md](sales/customer-returns.md), [purchase/supplier-returns.md](purchase/supplier-returns.md) |
| 7 | Dashboard Widgets | [dashboard/widgets.md](dashboard/widgets.md) |
| 8 | Report Pages | [inventory/stock-report.md](inventory/stock-report.md), [inventory/movement-history.md](inventory/movement-history.md), [inventory/low-stock-alerts.md](inventory/low-stock-alerts.md) |
| 9 | Nav groups, scheduling, formatting | — |

---

## Scheduling

Registered in `bootstrap/app.php` via `->withSchedule()`:

```php
Schedule::command('inventorix:expire-reservations')->hourly();
Schedule::command('inventorix:prune-movements')
    ->daily()
    ->when(fn () => config('inventorix.movement_prune_after_days') !== null);
```

---

## Verification

After each milestone:
- `php artisan migrate` — no errors
- `php artisan test --compact` — all green
- Visit `/admin` — no Filament registration errors
- `vendor/bin/pint --dirty` — no formatting violations
