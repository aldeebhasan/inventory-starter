# Reports Roadmap

All reports are custom Filament Pages with `InteractsWithTable` (read-only). Each report lives in its domain's navigation group. Documentation for each report is linked below.

---

## Phase 1 — Foundation

Must-have reports with high operational value. Build these first.

| # | Report | Nav Group | Sort | Doc | Status |
|---|--------|-----------|------|-----|--------|
| 1 | Low Stock Alerts | Inventory | 7 | [inventory/reports/low-stock-alerts.md](inventory/reports/low-stock-alerts.md) | Done |
| 2 | Stock Report | Inventory | 8 | [inventory/reports/stock-report.md](inventory/reports/stock-report.md) | Done |
| 3 | Inventory Valuation | Inventory | 9 | [inventory/reports/inventory-valuation.md](inventory/reports/inventory-valuation.md) | Done |
| 4 | Dashboard Widgets | Dashboard | — | [dashboard/widgets.md](dashboard/widgets.md) | Done |

---

## Phase 2 — Analytical

Core analytical reports for demand planning, trend analysis, and audit.

| # | Report | Nav Group | Sort | Doc | Status |
|---|--------|-----------|------|-----|--------|
| 5 | Stock Velocity & Runway | Inventory | 10 | [inventory/reports/stock-velocity.md](inventory/reports/stock-velocity.md) | Done |
| 6 | Product Movement Summary | Inventory | 11 | [inventory/reports/product-movement-summary.md](inventory/reports/product-movement-summary.md) | Done |
| 7 | Reorder Suggestions | Purchase | 3 | [purchase/reports/reorder-suggestions.md](purchase/reports/reorder-suggestions.md) | Done |
| 8 | Transaction Ledger | Inventory | 12 | [inventory/reports/transaction-ledger.md](inventory/reports/transaction-ledger.md) | Done |

---

## Phase 3 — Compliance & Cost

Audit compliance and cost tracking reports.

| # | Report | Nav Group | Sort | Doc | Status |
|---|--------|-----------|------|-----|--------|
| 9 | Adjustment Audit | Inventory | 13 | [inventory/reports/adjustment-audit.md](inventory/reports/adjustment-audit.md) | Documented |
| 10 | Purchase Cost Analysis | Purchase | 4 | [purchase/reports/purchase-cost-analysis.md](purchase/reports/purchase-cost-analysis.md) | Documented |
| 11 | Sales Summary | Sales | 4 | [sales/reports/sales-summary.md](sales/reports/sales-summary.md) | Documented |

---

## Phase 4 — Nice-to-Have

Additional reports that add value but are not critical for initial operation.

| # | Report | Nav Group | Sort | Doc | Status |
|---|--------|-----------|------|-----|--------|
| 12 | Dead Stock | Inventory | 14 | [inventory/reports/dead-stock.md](inventory/reports/dead-stock.md) | Documented |
| 13 | Overstock Alerts | Inventory | 15 | [inventory/reports/overstock-alerts.md](inventory/reports/overstock-alerts.md) | Documented |
| 14 | Expiring Stock | Inventory | 16 | [inventory/reports/expiring-stock.md](inventory/reports/expiring-stock.md) | Documented |
| 15 | Supplier Performance | Purchase | 5 | [purchase/reports/supplier-performance.md](purchase/reports/supplier-performance.md) | Documented |
| 16 | Customer Returns Analysis | Sales | 5 | [sales/reports/customer-returns-analysis.md](sales/reports/customer-returns-analysis.md) | Documented |
| 17 | ABC Analysis | Inventory | 17 | [inventory/reports/abc-analysis.md](inventory/reports/abc-analysis.md) | Documented |

---

## Architecture Rules

1. **Page pattern:** All reports follow `app/Filament/Pages/Inventories.php` — implement `HasTable`, use `InteractsWithTable`
2. **Navigation:** Reports stay in their domain group (Inventory / Purchase / Sales), not a separate "Reports" group
3. **No new migrations:** All data exists in inventorix tables + application order tables
4. **Performance:** Batch SQL aggregations, never per-row facade calls. Paginate 25 rows default
5. **Testing:** Each page gets a test file at `tests/Feature/{Group}/{ReportName}PageTest.php`
6. **View file:** Each page needs `resources/views/filament/pages/{kebab-name}.blade.php` containing `{{ $this->table }}`

## Navigation Order (complete)

```
Dashboard
Catalog    → Products (1), Categories (2), Brands (3), Units (4), Addons (5)
Sales      → Customers (1), Sale Orders (2), Refunded Orders (3), Sales Summary (4), Returns Analysis (5)
Purchase   → Suppliers (1), Purchase Orders (2), Reorder Suggestions (3), Cost Analysis (4), Supplier Performance (5)
Inventory  → Locations (1), Stocks (2), Stock Movements (3), Adjustments (4), Transfers (5),
             Reservations (6), Low Stock Alerts (7), Stock Report (8), Valuation (9),
             Velocity (10), Movement Summary (11), Transaction Ledger (12), Adjustment Audit (13),
             Dead Stock (14), Overstock (15), Expiring Stock (16), ABC Analysis (17)
```
