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
| Date Range | Date pair | `from` and `until` fields filter on `created_at` |

## Table Columns

| Column | Source | Notes |
|---|---|---|
| Product | `stockable.name` | searchable via `whereHasMorph` |
| Location | `inventorix_locations.name` | sortable |
| Type | `movements.type` | badge: Add (success/green) / Deduct (danger/red) |
| Qty | `movements.quantity` | sortable |
| Before → After | `before_quantity` / `after_quantity` | computed "10.00 → 5.00" |
| Cost / Unit | `movements.cost_per_unit` | nullable, shows `—` |
| Transaction Type | `transaction.type` | badge, shows `—` if none |
| Note | `movements.note` | truncated to 40 chars; full text on tooltip |
| Lot Ref | `movements.lot_reference` | hidden by default (toggleable) |
| External Ref | `movements.external_reference` | hidden by default (toggleable) |
| Date | `movements.created_at` | sortable, default DESC |

## Data Source

```php
Movement::query()
    ->with(['stockable', 'location', 'transaction'])
    ->whereHasMorph('stockable', [Product::class])
```

Default sort: `created_at DESC`.

## Tests

`tests/Feature/InventoryTransactionsPageTest.php`

- Renders successfully
- Lists movements after adding stock
- Shows both add and deduct movements
- Filters by product
- Filters by location
- Filters by movement type
- Defaults to descending date sort
