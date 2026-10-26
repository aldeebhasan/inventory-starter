# Reports Roadmap

All reports are custom Filament Pages with `InteractsWithTable` (read-only). Each report lives in its domain's navigation group, nested under that group's collapsible "Reports" item. Documentation for each report is linked below.

**Removed reports (merged into existing pages):**
- **Stock Report** → merged into Stocks page (categories, valuation, category filter, low stock filter added)
- **Transaction Ledger** → merged into Stock Movements page (transaction type/status filters, source document column added)
- **Adjustment Audit** → merged into Stock Movements page (filter by Adjustment transaction type to see adjustment movements)

---

## Phase 1 — Foundation

Must-have reports with high operational value. Build these first.

| # | Report | Nav Group | Sort | Doc | Status |
|---|--------|-----------|------|-----|--------|
| 1 | Low Stock Alerts | Inventory | 7 | [inventory/reports/low-stock-alerts.md](inventory/reports/low-stock-alerts.md) | Done |
| 2 | Inventory Valuation | Inventory | 9 | [inventory/reports/inventory-valuation.md](inventory/reports/inventory-valuation.md) | Done |
| 3 | Dashboard Widgets | Dashboard | — | [dashboard/widgets.md](dashboard/widgets.md) | Done |

---

## Phase 2 — Analytical

Core analytical reports for demand planning, trend analysis, and audit.

| # | Report | Nav Group | Sort | Doc | Status |
|---|--------|-----------|------|-----|--------|
| 4 | Stock Velocity & Runway | Inventory | 10 | [inventory/reports/stock-velocity.md](inventory/reports/stock-velocity.md) | Done |
| 5 | Product Movement Summary | Inventory | 11 | [inventory/reports/product-movement-summary.md](inventory/reports/product-movement-summary.md) | Done |
| 6 | Reorder Suggestions | Purchase | 3 | [purchase/reports/reorder-suggestions.md](purchase/reports/reorder-suggestions.md) | Done |

---

## Phase 3 — Compliance & Cost

Audit compliance and cost tracking reports.

| # | Report | Nav Group | Sort | Doc | Status |
|---|--------|-----------|------|-----|--------|
| 7 | Purchase Cost Analysis | Purchase | 4 | [purchase/reports/purchase-cost-analysis.md](purchase/reports/purchase-cost-analysis.md) | Done |
| 8 | Sales Summary | Sales | 4 | [sales/reports/sales-summary.md](sales/reports/sales-summary.md) | Done |

---

## Phase 4 — Nice-to-Have

Additional reports that add value but are not critical for initial operation.

| # | Report | Nav Group | Sort | Doc | Status |
|---|--------|-----------|------|-----|--------|
| 9 | Dead Stock | Inventory | 14 | [inventory/reports/dead-stock.md](inventory/reports/dead-stock.md) | Done |
| 10 | Overstock Alerts | Inventory | 15 | [inventory/reports/overstock-alerts.md](inventory/reports/overstock-alerts.md) | Done |
| 11 | Expiring Stock | Inventory | 16 | [inventory/reports/expiring-stock.md](inventory/reports/expiring-stock.md) | Done |
| 12 | Supplier Performance | Purchase | 5 | [purchase/reports/supplier-performance.md](purchase/reports/supplier-performance.md) | Done |
| 13 | Customer Returns Analysis | Sales | 5 | [sales/reports/customer-returns-analysis.md](sales/reports/customer-returns-analysis.md) | Done |
| 14 | ABC Analysis | Inventory | 17 | [inventory/reports/abc-analysis.md](inventory/reports/abc-analysis.md) | Done |

---

## Architecture Rules

1. **Page pattern:** All reports follow `app/Filament/Pages/Inventories.php` — implement `HasTable`, use `InteractsWithTable`
2. **Navigation:** Reports stay in their domain group (Inventory / Purchase / Sales), not a separate "… Reports" group. Each report page sets `$navigationGroup` to the module and `$navigationParentItem = 'Reports'`. The URL-less "Reports" parent item per module is registered in `AdminPanelProvider::reportsNavigationItem()`, which also marks it active when one of its report pages is open. The sidebar is an accordion (only the active group expanded; each "Reports" item collapses on its own) -- see `resources/views/filament/sidebar-accordion.blade.php`.
3. **No new migrations:** All data exists in inventorix tables + application order tables
4. **Performance:** Batch SQL aggregations, never per-row facade calls. Paginate 25 rows default
5. **Testing:** Each page gets a test file at `tests/Feature/{Group}/{ReportName}PageTest.php`
6. **View file:** Each page needs `resources/views/filament/pages/{kebab-name}.blade.php` containing `{{ $this->table }}`

## Navigation Order (complete)

```
Dashboard
Catalog    → Products (1), Categories (2), Brands (3), Units (4), Addons (5)
Sales      → Customers (1), Sale Orders (2), Refunded Orders (3)
             Reports ▸ Sales Summary (4), Returns Analysis (5)
Purchase   → Suppliers (1), Purchase Orders (2)
             Reports ▸ Reorder Suggestions (3), Cost Analysis (4), Supplier Performance (5)
Inventory  → Locations (1), Stocks (2), Stock Movements (3), Adjustments (4), Transfers (5),
             Stock Reservations (6)
             Reports ▸ Low Stock Alerts (7), Inventory Valuation (9), Stock Velocity (10),
                       Movement Summary (11), Dead Stock (14), Overstock Alerts (15),
                       Expiring Stock (16), ABC Analysis (17)
```

Numbers are `$navigationSort`. Each module's "Reports" item has sort 1000, so it always sits last in its group.
