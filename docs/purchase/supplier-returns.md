# Supplier Returns

**Navigation Group:** Purchase (sort 3)
**Purpose:** Warehouse sends goods back to a supplier → stock is deducted via `deductStock()`.
**References:** Purchase Orders via `ReturnOrder::purchaseOrder()` belongsTo (`purchase_order_id` FK, nullable). A purchase order lists its returns via `PurchaseOrder::supplierReturns()`.
**Shared model:** `ReturnOrder` with `type = supplier_return` -- scoped at the resource level. Customer refunds use the same model -- see `docs/sales/customer-returns.md`.
**Status Tracking:** Uses `TracksStatus` trait on `ReturnOrder` -- see `docs/status-tracking.md`.

**Do not:** Mix with Customer Returns. Supplier returns remove stock (reversing a purchase). Using the wrong type silently corrupts stock levels.

## Creation Modes

1. **From a Purchase Order** -- via the "Create Supplier Return" header action on `ViewPurchaseOrder` (visible on Received orders). It opens the create page with `purchase_order_id`, `supplier_id`, and `location_id` pre-filled from the query string. Picking a purchase order in the form does the same (and clears the lines). Items are limited to that order's products; unit and unit cost default to the purchase order line's, and quantity is capped at the line quantity.
2. **On the fly** -- no purchase order. Any product can be added (searched, 50 results max). Unit cost defaults to the product's `cost`.

The purchase order picker only searches **Received** orders, narrowed to the selected supplier when one is set.

## State Machine

```
Draft ──[Approve]──> Approved ──[Complete]──> Completed
  └──[Cancel]──> Cancelled      └──[Cancel]──> Cancelled  └──[Cancel]──> Cancelled

Approve:  status only -- no stock movement
Complete: stock check, then deductStock() per inventory item inside Inventorix::bulk() (TransactionType::Purchase)
Cancel from Draft/Approved: status only -- stock has not left yet, so nothing to reverse
Cancel from Completed: addStock() per inventory item inside Inventorix::bulk() (TransactionType::Reversal) -- puts the returned stock back
```

### Cancel a Completed return

Used when the supplier rejects the return or it was completed by mistake. All lines are restored in one `Inventorix::bulk()` call (causable = return order, reference = line item), using the base-unit quantity from `convertedQuantity()`. Restored stock is valued at the product's `cost` -- the same basis as refunded stock (see `docs/sales/customer-returns.md`). Non-inventory products are skipped. The confirmation modal warns that stock will be added back.

### Complete

1. **Stock check first.** For each inventory product, the total returned quantity (summed across lines, converted to the base unit) must be covered by **available** stock at the return's location -- `quantity - reserved_quantity`, so stock reserved for sale orders is never returned. If any product is short, a danger notification lists every short product, the action halts, and nothing changes.
2. **Deduct atomically.** All lines are deducted in one `Inventorix::bulk()` call (causable = return order, reference = line item), so a failure rolls back every line.
3. **Unit conversion.** Each line deducts `ReturnOrderItem::convertedQuantity()` -- the line quantity converted to the product's base unit.
4. **Non-inventory products** (`! $product->isInventory()`) are skipped.
5. **No cost on the deduction.** Inventorix records cost only on inbound movements; outbound stock is valued from the lots it consumes (FIFO). The line's unit cost (`price`) is the amount to claim back from the supplier, not a stock cost.

## Models

`ReturnOrder` (shared with customer refunds) -- see `docs/sales/customer-returns.md` for the full column list. Supplier-return specifics:
- `supplier_id` (required by the form), `purchase_order_id` (nullable, `nullOnDelete`) -- added by `2026_10_26_162113_add_purchase_order_id_to_return_orders_table`
- `purchaseOrder()` belongsTo PurchaseOrder
- Order number `SRT-XXXXX`, sequential per type

`ReturnOrderItem::convertedQuantity(): float` -- `quantity` converted to the product's base unit via `unit->convertQty()` (same as `SaleOrderItem` / `PurchaseOrderItem`).

`PurchaseOrder::supplierReturns()` -- hasMany ReturnOrder filtered to `type = supplier_return`.

`ReturnOrderStatus` enum: `Draft` (gray), `Approved` (info, supplier returns only), `Completed` (success), `Cancelled` (danger).

`ReturnOrderFactory` states: `supplierReturn()` (sets a supplier, clears customer), `approved()`, `completed()`, `cancelled()`.

## Filament Resource

**Path:** `app/Filament/Resources/SupplierReturns/` -- `SupplierReturnResource`, slug `supplier-returns`
**Icon:** `Heroicon::OutlinedArrowUturnRight`

Both `getEloquentQuery()` and `getRecordRouteBindingEloquentQuery()` scope to `type = supplier_return` (soft-deleted records included), so a customer refund ID 404s here. `CreateSupplierReturn::mutateFormDataBeforeCreate()` sets `type = supplier_return` and `created_by`.

### Form (`Schemas/SupplierReturnForm.php`)

| Field | Type | Notes |
|---|---|---|
| order_number | TextInput | disabled, generated by the model on create |
| supplier_id | Select | relationship('supplier', 'name'), searchable, required |
| purchase_order_id | Select | label 'Original Purchase Order', nullable, live, searches Received POs. On change: clears items, sets supplier and warehouse from the PO |
| location_id | Select | label 'Warehouse', required |
| reason | TextInput | required |
| notes | Textarea | nullable |
| items | Repeater | relationship(), min 1 -- Grid(5): product_id (span 2), quantity (min 0.001, max = PO line quantity when linked), unit_id (locked to the PO line unit when linked), price (label 'Unit Cost') |

### Table (`Tables/SupplierReturnsTable.php`)

| Column | Notes |
|---|---|
| order_number | sortable, searchable |
| supplier.name | sortable |
| status | badge with color per status |
| created_at | date, sortable |

Filters: `SelectFilter` on status, `TrashedFilter`. Row actions: View; Edit and Delete only on Draft.

### Pages

- `ListSupplierReturns` -- Create header action
- `CreateSupplierReturn` -- pre-fills `purchase_order_id` / `supplier_id` / `location_id` from the query string
- `ViewSupplierReturn` -- workflow header actions; infolist has "Return Details", "Lines", and "Status History"
- `EditSupplierReturn` -- Draft only (aborts 403 otherwise)

### Workflow Actions (header actions on `ViewSupplierReturn`)

| Action | Class | Visible on | Effect |
|---|---|---|---|
| Approve | `Actions/ApproveSupplierReturnAction` | Draft | status → Approved |
| Complete | `Actions/CompleteSupplierReturnAction` | Approved | stock check, then bulk `deductStock()` -- see "Complete" above; status → Completed |
| Cancel | `Actions/CancelSupplierReturnAction` | Draft, Approved, Completed | requires a reason, logs the status change with it. If Completed: restores stock -- see "Cancel a Completed return"; status → Cancelled |

### Purchase Order integration

- `PurchaseOrders/Actions/CreateSupplierReturnAction` -- header action on `ViewPurchaseOrder`, visible on Received, links to the create page with the PO pre-filled.
- `PurchaseOrderInfolist` -- "Supplier Returns" section (visible only when returns exist): Return # (links to the return), status badge, date, reason.

## Testing

`tests/Feature/Purchase/SupplierReturnResourceTest.php` -- model (SRT prefix, PO link, unit conversion), list (excludes customer refunds, refund IDs 404, status filter, soft delete), create (on the fly with product cost, from PO with PO line cost/unit, quantity cap, required fields), edit (Draft only), view, approve (no stock change), complete (deducts against the line, base-unit conversion, non-inventory skipped, insufficient stock blocks with no partial deduction, reserved stock not counted), cancel from Draft/Approved with logged reason, cancel from Completed (restores stock at product cost, base-unit conversion, non-inventory skipped), only Cancel offered on Completed, no actions on Cancelled, and the purchase order action and section.
