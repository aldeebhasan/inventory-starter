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

## Sorting

- Default: On Hand ASC.
- Product (sorted by product name through a subquery, since `stockable` is a morphTo), Location and On Hand are sortable.

## Data Source

`StockVelocityReport::getReportQuery(int $period)` (static, shared with the exporter) selects per stock row, as correlated subqueries over deduct movements of the same stockable + location since `now() - period`:

- `total_deducted` — `COALESCE(SUM(quantity), 0)`
- `peak_demand_date` — `DATE(created_at)` of the day with the highest summed deductions (`GROUP BY DATE(created_at) ORDER BY SUM(quantity) DESC LIMIT 1`)

Derived values (static helpers on the page, reused by the exporter): `available = quantity - reserved_quantity`, `avg_daily = total_deducted / period`, `days_of_stock = available / avg_daily` (`∞` when nothing was consumed), status from days of stock.

The **Status** filter is applied in SQL (`applyStatusFilter()`): days < N is rewritten as `available × period < N × total_deducted`, with `total_deducted = 0` counting as Healthy.

## Export

Header action `ExportAction` → `App\Filament\Exports\StockVelocityReportExporter` (model `Stock`), with option `period` set from the Period filter. It exports the page's filtered/sorted query, so the subquery columns are included — no per-row queries.

Columns: Product, Location, On Hand, Available, Avg Daily Consumption, Days of Stock, Peak Demand Day, Status.

## Performance

- One paginated query; aggregates are computed only for the returned page (previously velocity and per-day peak aggregates over all movements in the period were loaded on each render, and the peak lookup scanned that collection per row).
- Query count per render is constant (verified by a `countQueries` test).
