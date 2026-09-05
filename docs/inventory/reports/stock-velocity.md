# Stock Velocity & Runway

**Navigation Group:** Inventory
**Navigation Sort:** 10
**Type:** Custom Filament Page with table (`InteractsWithTable`)
**Path:** `app/Filament/Pages/StockVelocityReport.php`
**Icon:** `Heroicon::OutlinedBolt`

## Purpose

Shows the consumption rate and projected days-of-stock for every product-location combination. Enables proactive stockout prevention by highlighting items that will run out soonest.

## Filters

| Filter | Type | Notes |
|---|---|---|
| Location | Select | optional |
| Category | Select | optional |
| Period | Select | 7 / 14 / 30 / 60 / 90 days, default 30 |
| Status | Select | Critical (<7d) / Warning (<14d) / Healthy / All |

## Table Columns

| Column | Source | Notes |
|---|---|---|
| Product | products.name | searchable |
| Location | inventorix_locations.name | |
| On Hand | inventorix_stocks.quantity | |
| Available | quantity - reserved_quantity | computed |
| Avg Daily Consumption | deduct movements sum / period days | numeric(2) |
| Days of Stock | available / avg_daily_consumption | integer, `INF` if no consumption |
| Peak Demand Day | date with highest deductions in period | date format |
| Status | based on days_of_stock | badge: Critical (danger) / Warning (warning) / Healthy (success) |

Status thresholds:
- **Critical:** < 7 days of stock remaining
- **Warning:** < 14 days of stock remaining
- **Healthy:** >= 14 days

## Default Sort

Days of Stock ASC (most critical items first).

## Data Source

**Batch query** (not per-row facade calls — critical for performance):

```php
// Step 1: Get current stock levels
$stocks = Stock::query()
    ->with(['stockable.brand', 'location'])
    ->whereHasMorph('stockable', [Product::class])
    ->where('quantity', '>', 0)
    ->get();

// Step 2: Batch velocity — single query for all products
$velocities = Movement::query()
    ->where('type', MovementType::Deduct)
    ->where('created_at', '>=', now()->subDays($period))
    ->selectRaw('stockable_id, location_id, SUM(quantity) as total_deducted, COUNT(DISTINCT DATE(created_at)) as active_days')
    ->groupBy('stockable_id', 'location_id')
    ->get()
    ->keyBy(fn ($m) => $m->stockable_id . '-' . $m->location_id);

// Step 3: Peak demand day — single query
$peaks = Movement::query()
    ->where('type', MovementType::Deduct)
    ->where('created_at', '>=', now()->subDays($period))
    ->selectRaw('stockable_id, location_id, DATE(created_at) as demand_date, SUM(quantity) as daily_total')
    ->groupBy('stockable_id', 'location_id', DB::raw('DATE(created_at)'))
    ->orderByDesc('daily_total')
    ->get()
    ->unique(fn ($m) => $m->stockable_id . '-' . $m->location_id);
```

Merge in PHP: for each stock record, look up velocity and peak from the keyed collections.

- `avg_daily_consumption = total_deducted / $period`
- `days_of_stock = available / avg_daily_consumption` (guard division by zero → show ∞)

## Performance

- 3 queries total regardless of row count (stocks, velocities, peaks)
- Paginate 25 rows default
- No per-row facade calls (avoids N+1)
