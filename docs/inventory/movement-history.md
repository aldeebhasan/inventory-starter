# Movement History

**Navigation Group:** Inventory
**Type:** Custom Filament Page with table (`InteractsWithTable`)
**Path:** `app/Filament/Pages/MovementHistory.php`
**Icon:** `Heroicon::OutlinedArrowsUpDown`

## Purpose

Full audit trail of every stock movement. Useful for tracing discrepancies, reviewing activity per product or location, and compliance reporting.

## Filters

| Filter | Type | Notes |
|---|---|---|
| Product | Select | searchable, optional |
| Location | Select | optional |
| Movement Type | Select | Add / Deduct (MovementType enum) |
| Date Range | DateRangePicker | filters on created_at |

## Table Columns

| Column | Source | Notes |
|---|---|---|
| Product | stockable.name | via polymorphic relation |
| Location | inventorix_locations.name | |
| Type | movements.type | badge: Add (green) / Deduct (red) |
| Quantity | movements.quantity | |
| Before → After | before_quantity / after_quantity | formatted "100 → 95" |
| Cost / Unit | movements.cost | nullable |
| Note | movements.note | |
| Source | causable | linked label (e.g. "PO-00001") |
| Date | movements.created_at | sortable, default DESC |

## Data Source

```php
Movement::query()
    ->with(['stock.stockable', 'stock.location'])
    ->whereHasMorph('stockable', [Product::class], ...)
    ->when($productId, ...)
    ->when($locationId, ...)
    ->when($type, ...)
    ->when($dateFrom, fn ($q) => $q->where('created_at', '>=', $dateFrom))
    ->when($dateTo, fn ($q) => $q->where('created_at', '<=', $dateTo))
    ->orderByDesc('created_at')
```
