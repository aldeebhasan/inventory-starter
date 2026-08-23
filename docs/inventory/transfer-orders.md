# Transfer Orders

**Navigation Group:** Inventory
**Purpose:** Move stock between two warehouse locations with full send/receive lifecycle tracking.

**Do not:** Call `$product->transferStock()` directly. All location-to-location moves must go through a TransferOrder workflow so the movement is auditable, linked to a document, and supports in-transit tracking.

## State Machine

### Order Status

```
Draft ──[Confirm]──> Confirmed ──[Send]──> Sending ──[all Sent]──> InTransit ──[Receive]──> Receiving ──[all Received]──> Completed
  └──[Cancel]──> Cancelled    └──[Cancel]──> Cancelled                                                  └──[any Failed]──> PartiallyCompleted
                                                └──[any Failed]──> PartiallySent
                                                                        └──[Retry]──> Sending
```

- **Draft** — editable, can be cancelled.
- **Confirmed** — locked and validated. Ready to send. Can be cancelled.
- **Sending** — job dispatched to deduct stock from source. No actions except viewing item statuses.
- **InTransit** — all items successfully deducted from source. Goods are physically moving.
- **PartiallySent** — some items failed to deduct. Retry available for failed items.
- **Receiving** — job dispatched to add stock at destination.
- **Completed** — all items received at destination. Terminal state.
- **PartiallyCompleted** — some items failed during receive. Retry available for failed items.
- **Cancelled** — terminal. Only allowed from Draft or Confirmed.

### Item Status

```
Pending ──[send job]──> Sending ──> Sent ──[receive job]──> Receiving ──> Received
                              └──> Failed                         └──> Failed

Failed ──[Retry]──> re-enters current phase (Pending for send, Sent for receive)
```

- Items track `failure_reason` when status = Failed.

## Enums

### `TransferOrderStatus`

Values: `draft`, `confirmed`, `sending`, `in_transit`, `partially_sent`, `receiving`, `completed`, `partially_completed`, `cancelled`

### `TransferOrderItemStatus`

Values: `pending`, `sending`, `sent`, `receiving`, `received`, `failed`

## Models

- `TransferOrder` — `HasFactory`, `SoftDeletes`; casts: `status => TransferOrderStatus`; relationships: `fromLocation()` (FK: from_location_id), `toLocation()` (FK: to_location_id), `items()`, `pendingItems()`, `failedItems()`, `sentItems()`, `createdBy()`
- `TransferOrderItem` — relationships: `transferOrder()`, `product()`; casts: `item_status => TransferOrderItemStatus`; columns include `failure_reason` (nullable text)

### Model Method: `syncStatusFromItems(string $phase): void`

Accepts `'send'` or `'receive'`. Counts item statuses and updates the order status accordingly:
- Send phase: all Sent → InTransit, any Failed → PartiallySent
- Receive phase: all Received → Completed, any Failed → PartiallyCompleted

## Validation

### Form-level

- `to_location_id` must be `different:from_location_id` — prevents self-transfers.

### Confirm-time

Before locking the order, iterate all items and check `$item->product->stockAt($record->from_location_id) >= $item->quantity`. If any line fails, abort with a notification listing invalid lines. No status change occurs.

## Queue Architecture

Both phases are dispatched to a dedicated **`transfers`** queue.

### `SendTransferItemsJob`

- Processes all `Pending` items on the order.
- Per item: deducts stock from source location using `$product->deductStock()` with `TransactionType::Transfer`, `causable: $order`, `reference: $item`.
- Each item is individually try/caught — one failure doesn't abort the rest.
- After all items processed, calls `$order->syncStatusFromItems('send')`.
- `$tries = 3`, `$backoff = 10`

### `ReceiveTransferItemsJob`

- Processes all `Sent` items on the order.
- Per item: adds stock at destination location using `$product->addStock()` with same DTO pattern.
- Each item is individually try/caught.
- After all items processed, calls `$order->syncStatusFromItems('receive')`.
- `$tries = 3`, `$backoff = 10`

**Design rationale:** We use `deductStock` + `addStock` instead of `transferStock` so each phase is independently retryable. A failed receive doesn't leave stock in limbo — it's tracked as "Sent but not Received."

## Filament Resource

**Path:** `app/Filament/Resources/TransferOrders/`
**Icon:** `Heroicon::OutlinedArrowsRightLeft`

### Form (`Schemas/TransferOrderForm.php`)

| Field | Type | Notes |
|---|---|---|
| order_number | TextInput | auto-generated TO-XXXXX, unique, disabled on edit |
| from_location_id | Select | relationship('fromLocation', 'name'), searchable, required |
| to_location_id | Select | relationship('toLocation', 'name'), searchable, required, different from from_location_id |
| notes | Textarea | nullable |
| items | Repeater | relationship() — product_id (searchable, required), quantity (numeric, required, min 0.0001) |

### Table (`Tables/TransferOrdersTable.php`)

| Column | Notes |
|---|---|
| order_number | sortable, searchable |
| fromLocation.name | label 'From' |
| toLocation.name | label 'To' |
| status | badge — gray(Draft) / primary(Confirmed,Sending,Receiving) / info(InTransit) / success(Completed) / warning(PartiallySent,PartiallyCompleted) / danger(Cancelled) |
| items_count | counts('items'), label 'Lines' |
| created_at | date, sortable |

Filters: `SelectFilter` on status, `TrashedFilter`

Pages: `ListTransferOrders`, `CreateTransferOrder`, `ViewTransferOrder`, `EditTransferOrder` (Draft only)

### Workflow Actions (header actions on `ViewTransferOrder`)

| Action | Visible when | Effect |
|---|---|---|
| Confirm | Draft | Validates stock sufficiency → locks order (Confirmed), items → Pending |
| Send | Confirmed | Dispatches `SendTransferItemsJob` → order → Sending |
| Receive | InTransit | Dispatches `ReceiveTransferItemsJob` → order → Receiving |
| Retry Send | PartiallySent | Resets Failed → Pending, dispatches send job again |
| Retry Receive | PartiallyCompleted | Resets Failed → Sent, dispatches receive job again |
| Cancel | Draft, Confirmed | order → Cancelled |

All workflow actions require confirmation.

## View Page Infolist

- Header: `order_number`, `status` badge, `fromLocation.name`, `toLocation.name`, `notes`, `created_at`
- Items table (auto-polls when Sending or Receiving):

| Column | Notes |
|---|---|
| product.name | |
| quantity | |
| item_status | badge — gray(Pending) / primary(Sending,Receiving) / info(Sent) / success(Received) / danger(Failed) |
| failure_reason | shown only when Failed |

## Auto-generate `order_number`

In `TransferOrder::booted()` — pattern: `TO-XXXXX` using `static::withTrashed()->max('id') + 1`, zero-padded to 5 digits.

## Testing Requirements

File: `tests/Feature/Inventory/TransferOrderResourceTest.php`

Use `Queue::fake()` in workflow tests. Use `dispatchSync` in stock operation tests.

| Test | Covers |
|---|---|
| renders list page | ListTransferOrders loads |
| lists transfer orders | table shows records |
| creates transfer order with items | form save, DB assertions |
| validates required fields | from_location_id, to_location_id, items |
| validates from and to locations are different | form error on same location |
| confirm checks stock sufficiency | error notification, stays Draft if insufficient |
| confirm locks order | status = Confirmed, items = Pending |
| send dispatches job | Queue::assertPushed, order → Sending |
| send job deducts stock from source | stock decreases at from_location |
| send job marks items as Sent | item_status = sent |
| send job isolates failures | failed item = Failed + reason, others = Sent, order = PartiallySent |
| retry send re-queues failed items only | Failed → Pending, job dispatched |
| receive dispatches job | visible only when InTransit |
| receive job adds stock at destination | stock increases at to_location |
| receive job marks items as Received | item_status = received |
| receive job isolates failures | order = PartiallyCompleted |
| retry receive re-queues failed items only | Failed → Sent, job dispatched |
| cancel from draft and confirmed | → Cancelled |
| cancel hidden after Sending | action not visible |
| edit only for draft | 403 after confirm |
| soft delete | TrashedFilter works |
