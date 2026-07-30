# Inventory Management System — Architecture

## Overview

A production-grade inventory management system built on Laravel 13 + Filament v5. The `aldeebhasan/inventorix` package handles **all** inventory logic. The application layer is responsible for business documents and the Filament UI only.

---

## Navigation Structure

| Group | Module |
|---|---|
| Catalog | [Categories, Products](catalog.md) |
| Warehouse | [Locations](warehouse/locations.md) |
| Parties | [Suppliers](parties/suppliers.md), [Customers](parties/customers.md) |
| Operations | [Purchase Orders](operations/purchase-orders.md), [Sale Orders](operations/sale-orders.md), [Transfer Orders](operations/transfer-orders.md), [Adjustment Orders](operations/adjustment-orders.md), [Return Orders](operations/return-orders.md) |
| Reports | [Stock Report](reports/stock-report.md), [Movement History](reports/movement-history.md), [Low Stock Alerts](reports/low-stock-alerts.md) |

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
| 1 | Enums, Location, Supplier, Customer | [locations.md](warehouse/locations.md), [suppliers.md](parties/suppliers.md), [customers.md](parties/customers.md) |
| 2 | Purchase Orders | [purchase-orders.md](operations/purchase-orders.md) |
| 3 | Sale Orders | [sale-orders.md](operations/sale-orders.md) |
| 4 | Transfer Orders | [transfer-orders.md](operations/transfer-orders.md) |
| 5 | Adjustment Orders | [adjustment-orders.md](operations/adjustment-orders.md) |
| 6 | Return Orders | [return-orders.md](operations/return-orders.md) |
| 7 | Dashboard Widgets | [widgets.md](dashboard/widgets.md) |
| 8 | Report Pages | [reports/](reports/) |
| 9 | Nav groups, scheduling, formatting | [architecture.md](architecture.md) |

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
