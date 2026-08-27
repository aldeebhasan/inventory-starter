# Sale Orders

**Navigation Group:** Sales
**Purpose:** Sell goods to customers from a warehouse location, with reservation-based stock holding and full lifecycle tracking.
**Referenced by:** Customer Returns -- a customer return can reference a SO as its `originalOrder`.
**Status Tracking:** Uses `TracksStatus` trait -- see `docs/status-tracking.md`.

**Do not:** Call `$product->deductStock()` directly for a sale. All stock deductions from selling must go through a SaleOrder workflow. Do not skip the Confirm step -- dispatching without prior confirmation bypasses the stock hold and risks overselling.

## State Machine

```
Draft ──[Confirm]──> Confirmed ──[Dispatch]──> Dispatched ──[Deliver]──> Delivered ──[Fulfill]──> Fulfilled
  └──[Cancel]         └──[Cancel]                └──[Cancel]               └──[Cancel]
         └──────────────────────────────────────────────────────────────────────> Cancelled

Confirm:   reserve() per item -- stores reservation_id on each line item
Dispatch:  fulfillReservation() per item -- stock is deducted
Deliver:   no inventory operation -- marks goods as received by customer
Fulfill:   terminal -- order is complete
Cancel:    reverses inventory operations based on current stage (see below)
```

## Transition Control

Each status defines its allowed next statuses in code. This makes the workflow explicit and easy to modify.

### `SaleOrderStatus` Enum

```php
enum SaleOrderStatus: string
{
    case Draft      = 'draft';
    case Confirmed  = 'confirmed';
    case Dispatched = 'dispatched';
    case Delivered  = 'delivered';
    case Fulfilled  = 'fulfilled';
    case Cancelled  = 'cancelled';

    /**
     * Returns the statuses this status can transition to.
     * @return SaleOrderStatus[]
     */
    public function nextStatuses(): array
    {
        return match ($this) {
            self::Draft      => [self::Confirmed, self::Cancelled],
            self::Confirmed  => [self::Dispatched, self::Cancelled],
            self::Dispatched => [self::Delivered, self::Cancelled],
            self::Delivered  => [self::Fulfilled, self::Cancelled],
            self::Fulfilled  => [],
            self::Cancelled  => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->nextStatuses());
    }

    public function isFinal(): bool
    {
        return empty($this->nextStatuses());
    }

    public function isCancellable(): bool
    {
        return in_array(self::Cancelled, $this->nextStatuses());
    }
}
```

Use `canTransitionTo()` in action visibility and guards:
```php
->visible(fn (SaleOrder $record) => $record->status->canTransitionTo(SaleOrderStatus::Confirmed))
```

## Cancellation & Inventory Reversal

Cancellation is allowed at any non-final stage. The inventory reversal depends on what operations have been performed:

| Cancel from | Inventory reversal |
|---|---|
| Draft | None -- no inventory operations have occurred |
| Confirmed | `releaseReservation()` per item -- releases held stock |
| Dispatched | `addStock()` per item -- reverses the deduction (TransactionType::Reversal) |
| Delivered | `addStock()` per item -- same as Dispatched reversal |

```php
Action::make('cancel')
    ->visible(fn (SaleOrder $record) => $record->status->isCancellable())
    ->color('danger')
    ->requiresConfirmation()
    ->schema([
        Textarea::make('reason')->label('Cancellation Reason'),
    ])
    ->action(function (SaleOrder $record, array $data) {
        DB::transaction(function () use ($record, $data) {
            match ($record->status) {
                SaleOrderStatus::Confirmed => $this->releaseReservations($record),
                SaleOrderStatus::Dispatched,
                SaleOrderStatus::Delivered => $this->reverseDeductions($record),
                default => null, // Draft -- nothing to reverse
            };

            $record->logStatusChange($record->status, SaleOrderStatus::Cancelled, reason: $data['reason'] ?? null);
            $record->updateQuietly(['status' => SaleOrderStatus::Cancelled]);
        });
    })
```

Helper methods on the action or a service:

```php
private function releaseReservations(SaleOrder $record): void
{
    foreach ($record->items as $item) {
        if ($item->reservation_id) {
            $item->product->releaseReservation($item->reservation_id);
            $item->update(['reservation_id' => null]);
        }
    }
}

private function reverseDeductions(SaleOrder $record): void
{
    foreach ($record->items as $item) {
        $dto = new StockOperationDto(
            transactionType: TransactionType::Reversal,
            causable: $item, // line item as causable for idempotency
            reference: $record,
            note: "Cancel SO #{$record->order_number}",
            createdBy: auth()->id(),
        );
        $item->product->addStock($item->convertedQuantity(), $record->location_id, $dto);
    }
}
```

## Order Item Requirements

Sale order items are **mandatory**. An order cannot exist without at least one item.

- Form validation: `Repeater::make('items')->minItems(1)` -- prevents saving with zero items.
- Confirm action guard: verify `$record->items()->count() > 0` before proceeding.

## Handling Updates

When a sale order is updated (while in Draft), item changes must be handled properly:

- **Draft status only:** Editing is only allowed in Draft. Once confirmed, the form is locked.
- **Repeater handles adds/removes:** Filament's repeater with `->relationship()` manages item creation, updates, and deletion automatically.
- **No inventory sync needed in Draft:** Since no inventory operations occur until Confirm, editing items in Draft is safe.
- **After Confirm:** The order is locked. To change items, cancel the order and create a new one.

## Models

- `SaleOrder` -- `HasFactory`, `SoftDeletes`, `TracksStatus`; casts: `status => SaleOrderStatus`, `ordered_at`, `dispatched_at`, `delivered_at` as datetime; relationships: `customer()`, `location()`, `items()`, `createdBy()`, `customerReturns()` morphMany ReturnOrder, `statusLogs()` (from TracksStatus)
- `SaleOrderItem` -- `#[Fillable([..., 'unit_id', ...])]`; relationships: `saleOrder()`, `product()`, `unit()` belongsTo Unit, `reservation()` belongsTo `Aldeebhasan\Inventorix\Models\Reservation`; method: `convertedQuantity(): float` -- returns `quantity` converted to the product's base unit via `unit->convertQty()`

## Filament Resource

**Path:** `app/Filament/Resources/SaleOrders/`
**Icon:** `Heroicon::OutlinedBanknotes`

### Form (`Schemas/SaleOrderForm.php`)

| Field | Type | Notes |
|---|---|---|
| order_number | TextInput | auto-generated SO-XXXXX, unique |
| customer_id | Select | relationship('customer', 'name'), searchable, required |
| location_id | Select | relationship('location', 'name'), searchable, required |
| ordered_at | DateTimePicker | default now(), required |
| notes | Textarea | nullable |
| items | Repeater | relationship(), minItems(1), defaultItems(1) -- Grid(5): product_id (span 2, live), unit_id (span 1, auto-fills from product.unit_id, shows product unit + derived units), quantity (span 1), unit_price (span 1) |

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

**ConfirmAction** -- `Draft -> Confirmed` + reserve stock
```php
Action::make('confirm')
    ->visible(fn (SaleOrder $record) => $record->status->canTransitionTo(SaleOrderStatus::Confirmed))
    ->requiresConfirmation()
    ->action(function (SaleOrder $record) {
        abort_if($record->items()->count() === 0, 422, 'Order must have at least one item.');

        DB::transaction(function () use ($record) {
            foreach ($record->items as $item) {
                $dto = new StockOperationDto(
                    transactionType: TransactionType::Sale,
                    causable: $item, // line item as causable for idempotency
                    reference: $record,
                    createdBy: auth()->id(),
                );
                $reservation = $item->product->reserve($item->convertedQuantity(), $record->location_id, $dto);
                $item->update(['reservation_id' => $reservation->id]);
            }
            $record->update(['status' => SaleOrderStatus::Confirmed]);
        });
    })
```

**DispatchAction** -- `Confirmed -> Dispatched` + fulfill reservations (deduct stock)
```php
Action::make('dispatch')
    ->visible(fn (SaleOrder $record) => $record->status->canTransitionTo(SaleOrderStatus::Dispatched))
    ->requiresConfirmation()
    ->action(function (SaleOrder $record) {
        DB::transaction(function () use ($record) {
            foreach ($record->items as $item) {
                if ($item->reservation_id) {
                    $item->product->fulfillReservation($item->reservation_id);
                }
            }
            $record->update(['status' => SaleOrderStatus::Dispatched, 'dispatched_at' => now()]);
        });
    })
```

**DeliverAction** -- `Dispatched -> Delivered` (no inventory operation)
```php
Action::make('deliver')
    ->visible(fn (SaleOrder $record) => $record->status->canTransitionTo(SaleOrderStatus::Delivered))
    ->requiresConfirmation()
    ->action(fn (SaleOrder $record) => $record->update([
        'status' => SaleOrderStatus::Delivered,
        'delivered_at' => now(),
    ]))
```

**FulfillAction** -- `Delivered -> Fulfilled` (terminal, order complete)
```php
Action::make('fulfill')
    ->visible(fn (SaleOrder $record) => $record->status->canTransitionTo(SaleOrderStatus::Fulfilled))
    ->requiresConfirmation()
    ->action(fn (SaleOrder $record) => $record->update(['status' => SaleOrderStatus::Fulfilled]))
```

**CreateReturnAction** -- visible on Dispatched, Delivered, or Fulfilled orders
```php
Action::make('createReturn')
    ->label('Create Return')
    ->icon(Heroicon::OutlinedArrowUturnLeft)
    ->visible(fn (SaleOrder $record) => in_array($record->status, [
        SaleOrderStatus::Dispatched, SaleOrderStatus::Delivered, SaleOrderStatus::Fulfilled,
    ]))
    ->url(fn (SaleOrder $record) => CustomerReturnResource::getUrl('create', [
        'original_order_id' => $record->id,
        'customer_id' => $record->customer_id,
    ]))
```

**CancelAction** -- any non-final status -> Cancelled + reverse inventory (see Cancellation section above)

### View Page

Includes a "Status History" section showing all status transitions from `statusLogs()` -- see `docs/status-tracking.md` for the display pattern.

## Testing Requirements

File: `tests/Feature/Sales/SaleOrderResourceTest.php`

| Test | Covers |
|---|---|
| renders list page | ListSaleOrders loads |
| lists sale orders | table shows records |
| renders create page | CreateSaleOrder loads |
| creates sale order with items | form save, DB assertions |
| validates required fields | customer_id, location_id, items (minItems) |
| rejects order without items | minItems(1) validation |
| renders view page | ViewSaleOrder loads |
| confirm reserves stock | reservation created, reservation_id stored on item |
| confirm requires items | aborts if no items |
| confirm transitions to Confirmed | status change, canTransitionTo guard |
| dispatch fulfills reservations | stock deducted, dispatched_at set |
| dispatch transitions to Dispatched | status change |
| deliver transitions to Delivered | delivered_at set, no inventory change |
| fulfill transitions to Fulfilled | terminal state |
| cancel from Draft | no inventory reversal, status Cancelled |
| cancel from Confirmed releases reservations | releaseReservation called, reservation_id cleared |
| cancel from Dispatched reverses deductions | addStock called with Reversal type |
| cancel from Delivered reverses deductions | same as Dispatched cancel |
| cancel not available on Fulfilled | action hidden |
| cancel not available on Cancelled | action hidden |
| status transitions are logged | StatusLog created for each transition |
| cancel logs reason | reason stored in StatusLog |
| edit only for Draft | 403 after confirm |
| soft delete | TrashedFilter works |
| nextStatuses returns correct values | enum method coverage |
| canTransitionTo validates correctly | enum method coverage |
