# Expiring Stock Report

**Navigation Group:** Inventory
**Navigation Sort:** 16
**Type:** Custom Filament Page with table (`InteractsWithTable`)
**Path:** `app/Filament/Pages/ExpiringStock.php`
**Icon:** `Heroicon::OutlinedClock`

## Purpose

Shows inventory lots nearing expiration so warehouse staff can prioritize FEFO (First Expired, First Out) picking or mark down items. Only relevant when `expires_at` and `lot_reference` are populated during purchase receiving.

## Prerequisite

This report is useful only if `expires_at` is populated on Add movements during purchase order receiving. If the system does not track lot expiration, this page can be deferred.

## Filters

| Filter | Type | Notes |
|---|---|---|
| Location | Select | optional |
| Days Until Expiry | Select | 7 / 14 / 30 / 60 / 90, default 30 |
| Show Expired | Toggle | include lots already past expiry, default false |
| Product | Select | optional, searchable |

## Table Columns

| Column | Source | Notes |
|---|---|---|
| Product | products.name via stockable | searchable |
| Location | inventorix_locations.name | |
| Lot Reference | inventorix_movements.lot_reference | |
| Qty Remaining | quantity - consumed_quantity | numeric(3) |
| Cost/Unit | inventorix_movements.cost_per_unit | numeric(3) |
| Expires At | inventorix_movements.expires_at | date format |
| Days Until Expiry | DATEDIFF(expires_at, now) | integer, negative = expired |
| Status | based on days_until_expiry | badge: Expired (danger) / Expiring Soon (warning) / OK (success) |

Status thresholds:
- **Expired:** days_until_expiry < 0
- **Expiring Soon:** days_until_expiry <= filter value
- **OK:** days_until_expiry > filter value

## Default Sort

Expires At ASC (soonest expiry first).

## Data Source

```php
Movement::query()
    ->with(['stockable', 'location'])
    ->whereHasMorph('stockable', [Product::class])
    ->where('type', MovementType::Add)
    ->whereNotNull('expires_at')
    ->whereRaw('quantity - COALESCE(consumed_quantity, 0) > 0')
```

- Days Until Expiry filter: `expires_at <= now() + N days`.
- Show Expired off: `expires_at >= today()` — lots expiring today (0 days) are still shown.
- Derived values (`qtyRemaining`, `daysUntilExpiry`, `status`) are static helpers on the page, reused by the exporter.

## Export

Header action `ExportAction` → `App\Filament\Exports\ExpiringStockExporter` (model `Movement`), with option `days_threshold` set from the Days Until Expiry filter (used for the Expiring Soon / OK status). `stockable` and `location` are eager-loaded — no per-row queries.

Columns: Product, Location, Lot Reference, Qty Remaining, Cost/Unit, Expires At, Days Until Expiry, Status.

## Performance

- One paginated query plus eager loads; query count per render is constant (verified by a `countQueries` test).
