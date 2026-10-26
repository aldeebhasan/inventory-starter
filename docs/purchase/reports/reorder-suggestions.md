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
| Location | Select | optional (`location_id`) |
| Category | Select | optional (`whereHasMorph` on product categories) |
| Urgency | Select | Critical (<7d) / Warning (<14d) / All low stock, default All. Filters on days of stock remaining in SQL; rows with no consumption in the window (∞ days) only appear under "All". |

Before this change the Urgency filter did nothing: its query closure returned the query unchanged.

## Table Columns

| Column | Source | Notes |
|---|---|---|
| Product | products.name | searchable; sortable (subquery on `products.name`) |
| Location | inventorix_locations.name | sortable |
| Available | quantity - reserved_quantity | computed |
| Min Threshold | `min_quantity` subquery (location threshold, else global) | |
| Avg Daily Consumption | `total_deducted` / 30 | numeric(2) |
| Days Until Stockout | available / avg_daily_consumption | integer, `∞` if no consumption |
| Suggested Reorder Qty | max_threshold - available, or 30d consumption if no max; at least min - available | integer |
| Preferred Supplier | first supplier from product_supplier pivot | supplier name |
| Supplier Unit Cost | product_supplier.unit_cost | numeric(3), fallback to product.cost |
| Est. Reorder Cost | suggested_qty x supplier_unit_cost | numeric(2) |

## Default Sort

`quantity` ASC (lowest stock first).

## Row Actions

**Create Purchase Order**: redirects to `CreatePurchaseOrder` with `product_id` and `supplier_id` (preferred supplier) prefilled.

## Data Source

Everything is computed in one SQL query. The page used to load every low-stock stock ID (`Inventorix::lowStockItems()`), every product threshold, and every 30-day velocity aggregate into PHP, whatever page was on screen. None of that is loaded any more.

```php
Stock::query()
    ->with(['stockable' => fn (MorphTo $m) => $m->morphWith([Product::class => ['suppliers']]), 'location'])
    ->where('inventorix_stocks.stockable_type', Product::class)
    // same low-stock rule as Inventorix::lowStockItems(): any matching threshold (location or global) with quantity <= min
    ->whereExists(matchingThresholds()->whereColumn('inventorix_stocks.quantity', '<=', 'inventorix_thresholds.min_quantity'))
    ->select('inventorix_stocks.*')
    ->selectSub(thresholdValue('min_quantity'), 'min_quantity')   // location-specific threshold preferred over global
    ->selectSub(thresholdValue('max_quantity'), 'max_quantity')
    ->selectSub(recentDeductions('SUM(quantity)'), 'total_deducted') // deduct movements, last VELOCITY_DAYS (30) days
```

Days of stock (also used by the Urgency filter) is `(quantity - reserved_quantity) * 30 / NULLIF(SUM(deducted in window), 0)`. A product with no consumption gives NULL, which means infinite days. The filter applies it as `whereRaw('(subquery) < ?', [7|14])`.

The eager load uses `morphWith` rather than `'stockable.suppliers'` because nested morph paths break the queued export's query serialization.

Suggested Reorder Qty logic (`ReorderSuggestions::getSuggestedQty()`, shared with the exporter):
```php
$suggested = $max !== null ? $max - $available : ceil($avgDaily * 30);
$suggested = max($suggested, $min - $available, 0);
```

## Export

Header `ExportAction` → `App\Filament\Exports\ReorderSuggestionsExporter` (model `Stock`). Columns: Product, Location, Available, Min Threshold, Avg Daily Consumption, Days Until Stockout (blank = ∞), Suggested Reorder Qty, Preferred Supplier, Supplier Unit Cost, Est. Reorder Cost. The export uses the table's filtered and sorted query.

## Performance

- One table query (threshold and velocity subqueries) plus eager loads for products, suppliers, and locations. The query count stays the same however many rows are shown (covered by a `countQueries` test).
- Paginate 25 rows default
