# Transaction Ledger

**Navigation Group:** Inventory
**Navigation Sort:** 12
**Type:** Custom Filament Page with table (`InteractsWithTable`)
**Path:** `app/Filament/Pages/TransactionLedger.php`
**Icon:** `Heroicon::OutlinedClipboardDocumentList`

## Purpose

High-level audit trail of all inventory transactions, each linked to its source document (PO, SO, Transfer, Adjustment, etc.). Unlike the Stock Movements page which shows individual quantity changes, this page shows transactions — the grouping layer above movements. Used for audit compliance and tracing stock changes back to business documents.

## Filters

| Filter | Type | Notes |
|---|---|---|
| Date From | DatePicker | optional |
| Date Until | DatePicker | optional |
| Transaction Type | Select | Purchase / Sale / Transfer / Adjustment / Manual / Reversal |
| Status | Select | Pending / Completed / RolledBack |

## Table Columns

| Column | Source | Notes |
|---|---|---|
| UUID | inventorix_transactions.uuid | short format (first 8 chars) with tooltip for full |
| Type | inventorix_transactions.type | badge with color per type |
| Status | inventorix_transactions.status | badge: Completed (success), Pending (warning), RolledBack (danger) |
| Source Document | causable relationship | linked: "PO-00001", "SO-00003", etc. Uses `order_number` if available |
| Movements | count of related movements | `withCount('movements')` |
| Total Qty | sum of movement quantities | `withSum('movements', 'quantity')` |
| Created At | inventorix_transactions.created_at | datetime format |

Transaction type badge colors:
- Purchase → primary
- Sale → success
- Transfer → info
- Adjustment → warning
- Manual → gray
- Reversal → danger

## Default Sort

Created At DESC (newest first).

## Data Source

```php
Transaction::query()
    ->with('causable')
    ->withCount('movements')
    ->withSum('movements', 'quantity')
    ->when($type, fn ($q) => $q->where('type', $type))
    ->when($status, fn ($q) => $q->where('status', $status))
    ->when($dateFrom, fn ($q) => $q->where('created_at', '>=', $dateFrom))
    ->when($dateUntil, fn ($q) => $q->where('created_at', '<=', $dateUntil))
```

## Causable Display Logic

```php
TextColumn::make('causable')
    ->formatStateUsing(function ($record) {
        $causable = $record->causable;
        if (!$causable) return '—';
        return $causable->order_number ?? class_basename($causable) . ' #' . $causable->id;
    })
    ->url(fn ($record) => /* link to the causable's view page */)
```

## Performance

- Single query with eager loading and aggregates
- No per-row computation
- Paginate 25 rows default
