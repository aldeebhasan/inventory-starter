# Sale Orders

**Navigation Group:** Sales
**Purpose:** Sell goods to customers from a warehouse location, with reservation-based stock holding and full lifecycle tracking.
**Referenced by:** Refunded Orders -- a refunded order can reference a SO as its `originalOrder`.
**Status Tracking:** Uses `TracksStatus` trait -- see `docs/status-tracking.md`.

**Do not:** Call `$product->deductStock()` directly for a sale. All stock deductions from selling must go through a SaleOrder workflow. Do not skip the Confirm step -- shipping without prior confirmation bypasses the stock hold and risks overselling.

## State Machine

```
Draft ──[Confirm]──> Confirmed ──[Pick]──> Picked ──[Ship]──> Shipped ──[Fulfill]──> Fulfilled
  └──[Cancel]         └──[Cancel]           └──[Cancel]
         └──────────────────────────────────────────────> Cancelled

Confirm:   reserve() per item -- stores reservation_id on each line item
Pick:      no inventory operation -- marks items as picked
Ship:      fulfillReservation() per item -- stock is deducted, shipped_at set
Fulfill:   terminal -- order received by customer and complete
Cancel:    releases reservations for items that have them (Draft/Confirmed/Picked only)
```

## Cancellation & Inventory Reversal

Cancellation is allowed from Draft, Confirmed, and Picked statuses only. After Ship, stock is already deducted -- use a Refunded Order instead.

| Cancel from | Inventory reversal |
|---|---|
| Draft | None -- no inventory operations have occurred |
| Confirmed | `releaseReservation()` per item -- releases held stock |
| Picked | `releaseReservation()` per item -- same as Confirmed |

## Order Item Requirements

Sale order items are **mandatory**. An order cannot exist without at least one item.

- Form validation: `Repeater::make('items')->minItems(1)` -- prevents saving with zero items.

## Handling Updates

When a sale order is updated (while in Draft), item changes must be handled properly:

- **Draft status only:** Editing is only allowed in Draft. Once confirmed, the form is locked.
- **Repeater handles adds/removes:** Filament's repeater with `->relationship()` manages item creation, updates, and deletion automatically.
- **No inventory sync needed in Draft:** Since no inventory operations occur until Confirm, editing items in Draft is safe.
- **After Confirm:** The order is locked. To change items, cancel the order and create a new one.

## Models

- `SaleOrder` -- `HasFactory`, `SoftDeletes`, `TracksStatus`; casts: `status => SaleOrderStatus`, `ordered_at`, `shipped_at` as datetime; relationships: `customer()`, `location()`, `items()`, `createdBy()`, `statusLogs()` (from TracksStatus)
- `SaleOrderItem` -- `#[Fillable([..., 'unit_id', ...])]`; relationships: `saleOrder()`, `product()`, `unit()` belongsTo Unit, `reservation()` belongsTo `Aldeebhasan\Inventorix\Models\Reservation`; method: `convertedQuantity(): float` -- returns `quantity` converted to the product's base unit via `unit->convertQty()`

## `SaleOrderStatus` Enum

```php
enum SaleOrderStatus: string
{
    case Draft     = 'draft';
    case Confirmed = 'confirmed';
    case Picked    = 'picked';
    case Shipped   = 'shipped';
    case Fulfilled = 'fulfilled';
    case Cancelled = 'cancelled';
}
```

## Filament Resource

**Path:** `app/Filament/Resources/SaleOrders/`
**Icon:** `Heroicon::OutlinedBanknotes`

### Form (`Schemas/SaleOrderForm.php`)

| Field | Type | Notes |
|---|---|---|
| order_number | TextInput | auto-generated SO-XXXXX, unique |
| customer_id | Select | relationship('customer', 'name'), searchable, required |
| location_id | Select | location options, searchable, required |
| ordered_at | DateTimePicker | default now(), required |
| notes | Textarea | nullable |
| items | Repeater | relationship(), minItems(1), defaultItems(1) -- Grid(5): product_id (span 2, live, auto-fills unit_id + unit_price from product), unit_id (span 1), quantity (span 1), unit_price (span 1) |

### Table (`Tables/SaleOrdersTable.php`)

| Column | Notes |
|---|---|
| order_number | sortable, searchable |
| customer.name | sortable |
| location.name | |
| status | badge with color per status |
| ordered_at | date, sortable |
| items_count | counts('items'), label 'Lines' |

Filters: `SelectFilter` on status, `SelectFilter` on customer, `TrashedFilter`

Pages: `ListSaleOrders`, `CreateSaleOrder`, `ViewSaleOrder`, `EditSaleOrder` (Draft only)

### Workflow Actions (header actions on `ViewSaleOrder`)

| Action | Transition | Inventory operation |
|---|---|---|
| ConfirmSaleOrderAction | Draft → Confirmed | `reserve()` per item, stores `reservation_id` |
| PickSaleOrderAction | Confirmed → Picked | none |
| ShipSaleOrderAction | Picked → Shipped | `fulfillReservation()` per item, sets `shipped_at` |
| FulfillSaleOrderAction | Shipped → Fulfilled | none (terminal) |
| CancelSaleOrderAction | Draft/Confirmed/Picked → Cancelled | releases reservations if any |
| CreateSaleReturnAction | visible on Shipped/Fulfilled | links to Refunded Order create page |

### View Page

Includes a "Status History" section showing all status transitions from `statusLogs()` -- see `docs/status-tracking.md` for the display pattern.

## Testing

File: `tests/Feature/Sales/SaleOrderResourceTest.php`
