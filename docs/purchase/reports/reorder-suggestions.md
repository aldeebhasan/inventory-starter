# Reorder Suggestions

**Navigation Group:** Purchase
**Navigation Sort:** 3
**Type:** Custom Filament Page with table (`InteractsWithTable`)
**Path:** `app/Filament/Pages/ReorderSuggestions.php`
**Icon:** `Heroicon::OutlinedClipboardDocumentCheck`

## Purpose

Combines low stock alerts with consumption velocity and supplier pricing to produce actionable reorder recommendations. Shows what to reorder, how much, from which supplier, and at what cost. The most actionable planning report for purchasing teams.

## Filters

| Filter | Type | Notes |
|---|---|---|
| Location | Select | optional |
| Category | Select | optional |
| Urgency | Select | Critical (<7d) / Warning (<14d) / All low stock, default All |

## Table Columns

| Column | Source | Notes |
|---|---|---|
| Product | products.name | searchable |
| Location | inventorix_locations.name | |
| Available | quantity - reserved_quantity | computed |
| Min Threshold | inventorix_thresholds.min_quantity | |
| Avg Daily Consumption | deduct movements sum / 30 days | numeric(2) |
| Days Until Stockout | available / avg_daily_consumption | integer, `∞` if no consumption |
| Suggested Reorder Qty | max_threshold - available, or 30d consumption if no max | integer |
| Preferred Supplier | from product_supplier pivot (first supplier) | supplier name |
| Supplier Unit Cost | product_supplier.unit_cost | numeric(3), fallback to product.cost |
| Est. Reorder Cost | suggested_qty x supplier_unit_cost | numeric(2), currency |

## Default Sort

Days Until Stockout ASC (most urgent first).

## Row Actions

**Create Purchase Order** — redirects to `CreatePurchaseOrder` page with:
- `supplier_id` pre-selected (from preferred supplier)
- One item pre-filled with the product, suggested quantity, and supplier cost

## Data Source

```php
// Step 1: Get low stock items
$lowStockItems = Inventorix::lowStockItems(
    stockableType: Product::class,
    locationId: $locationId,
);

// Step 2: Batch velocity (same as Stock Velocity report)
$velocities = Movement::query()
    ->where('type', MovementType::Deduct)
    ->where('created_at', '>=', now()->subDays(30))
    ->selectRaw('stockable_id, location_id, SUM(quantity) as total_deducted')
    ->groupBy('stockable_id', 'location_id')
    ->get()
    ->keyBy(fn ($m) => $m->stockable_id . '-' . $m->location_id);

// Step 3: Supplier info from product_supplier pivot
// Product::with('suppliers') — use first supplier or the one with lowest cost
```

Suggested Reorder Qty logic:
```php
if ($maxThreshold) {
    $suggestedQty = $maxThreshold - $available;
} else {
    // Order 30 days of consumption
    $suggestedQty = ceil($avgDailyConsumption * 30);
}
$suggestedQty = max($suggestedQty, $minThreshold - $available);
```

## Performance

- 2 queries (low stock + velocity batch) + eager load suppliers
- Paginate 25 rows default
