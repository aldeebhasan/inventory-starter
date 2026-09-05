# Adjustment Audit Report

**Navigation Group:** Inventory
**Navigation Sort:** 13
**Type:** Custom Filament Page with table (`InteractsWithTable`)
**Path:** `app/Filament/Pages/AdjustmentAuditReport.php`
**Icon:** `Heroicon::OutlinedDocumentMagnifyingGlass`

## Purpose

Flat, item-level view of all stock adjustments across all adjustment orders. Shows before/after quantities, operation type, reason, and who performed the adjustment. Designed for audit compliance — not for managing adjustment orders (that's the StockAdjustment resource).

## Filters

| Filter | Type | Notes |
|---|---|---|
| Date From | DatePicker | optional |
| Date Until | DatePicker | optional |
| Location | Select | optional, from adjustment order's location |
| Product | Select | optional, searchable |
| Created By | Select | optional, from users who created adjustments |
| Status | Select | optional, item-level status (Pending/Applied/Failed) |

## Table Columns

| Column | Source | Notes |
|---|---|---|
| Adjustment # | stock_adjustments.order_number | linked to ViewStockAdjustment |
| Date | stock_adjustments.created_at | datetime format |
| Location | stock_adjustments.location.name | |
| Product | stock_adjustment_items.product.name | searchable |
| Operation | stock_adjustment_items.operation | badge: Add (green) / Deduct (red) / Set (blue) |
| Quantity | stock_adjustment_items.quantity | |
| Cost | stock_adjustment_items.cost | nullable, numeric(3) |
| Before Stock | stock_adjustment_items.current_stock | snapshot at time of creation |
| After Stock | current_stock + qty (Add) or - qty (Deduct) or qty (Set) | computed |
| Reason | stock_adjustments.reason | truncated with tooltip |
| Created By | stock_adjustments.createdBy.name | |
| Item Status | stock_adjustment_items.status | badge: Applied (success) / Failed (danger) / Pending (gray) |
| Failure Reason | stock_adjustment_items.failure_reason | visible only if failed, truncated |

## Default Sort

Date DESC (newest first).

## Data Source

```php
StockAdjustmentItem::query()
    ->with([
        'stockAdjustment.location',
        'stockAdjustment.createdBy',
        'product',
    ])
    ->when($dateFrom, fn ($q) => $q->whereHas('stockAdjustment',
        fn ($sa) => $sa->where('created_at', '>=', $dateFrom)))
    ->when($locationId, fn ($q) => $q->whereHas('stockAdjustment',
        fn ($sa) => $sa->where('location_id', $locationId)))
    ->when($productId, fn ($q) => $q->where('product_id', $productId))
    ->when($userId, fn ($q) => $q->whereHas('stockAdjustment',
        fn ($sa) => $sa->where('created_by', $userId)))
    ->when($status, fn ($q) => $q->where('status', $status))
```

## Performance

- Single query with eager loading
- Paginate 25 rows default
- Filters push down to SQL (no in-memory filtering)
