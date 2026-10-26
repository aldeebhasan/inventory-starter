# Stock Movements (Inventory Transactions)

**Navigation Group:** Inventory
**Navigation Label:** Stock Movements
**Type:** Custom Filament Page with table (`InteractsWithTable`)
**Path:** `app/Filament/Pages/InventoryTransactions.php`
**Icon:** `Heroicon::OutlinedArrowsUpDown`

## Purpose

Full audit trail of every stock movement for inventory products. Shows type, quantity deltas, cost, transaction source, and timestamps. Useful for tracing discrepancies and reviewing activity per product or location.

## Filters

| Filter | Type | Notes |
|---|---|---|
| Product | Select | searchable; scoped to `Product` morphable type |
| Location | Select | active locations only |
| Movement Type | Select | `add` / `deduct` from `MovementType` enum |
| Transaction Type | Select | `whereHas('transaction')` on `type` |
| Transaction Status | Select | `whereHas('transaction')` on `status` |
| Date Range | Date pair | `from` and `until` fields filter on `created_at` |

## Table Columns

| Column | Source | Notes |
|---|---|---|
| Product | `stockable.name` | searchable via `whereHasMorph`; sortable via a `products.name` subquery (the default morphTo relationship sort produced invalid SQL) |
| Location | `inventorix_locations.name` | sortable |
| Type | `movements.type` | badge: Add (success/green) / Deduct (danger/red) |
| Qty | `movements.quantity` | sortable |
| Before → After | `before_quantity` / `after_quantity` | computed "10.00 → 5.00" |
| Cost / Unit | `movements.cost_per_unit` | nullable, shows `—` |
| Transaction Type | `transaction.type` | badge, shows `—` if none |
| Source Document | `transaction.causable` | `order_number` or `Class #id`; hidden by default (toggleable) |
| Note | `movements.note` | truncated to 40 chars; full text on tooltip |
| Lot Ref | `movements.lot_reference` | hidden by default (toggleable) |
| External Ref | `movements.external_reference` | hidden by default (toggleable) |
| Date | `movements.created_at` | sortable, default DESC |

## Data Source

```php
Movement::query()
    ->with(['stockable', 'location', 'transaction.causable'])
    ->whereHasMorph('stockable', [Product::class])
```

Default sort: `created_at DESC`. `transaction.causable` is eager-loaded so the Source Document column does not lazy-load the order per row (constant query count with all columns visible).

## Header Actions

**Export** — `ExportAction` with `App\Filament\Exports\InventoryTransactionsExporter` (CSV/XLSX, queued). Exports the current filtered/sorted table query. Columns: Product, Location, Type, Qty, Before, After, Cost / Unit, Transaction Type, Source Document, Note, Lot Ref, External Ref, Date. `modifyQuery()` eager-loads `stockable`, `location`, `transaction.causable`.

## Tests

`tests/Feature/InventoryTransactionsPageTest.php`

- Renders successfully
- Lists movements after adding stock
- Shows both add and deduct movements
- Filters by product
- Filters by location
- Filters by movement type
- Defaults to descending date sort
- Query count does not grow with rows (all columns toggled on)
- Source document from eager-loaded causable, product-name sort
- Export: fake dispatch, exporter row values, real CSV export
