# Sale Orders

**Navigation Group:** Sales
**Purpose:** Sell goods to customers from a warehouse location, with reservation-based stock holding.
**Referenced by:** Customer Returns — a customer return can reference a SO as its `originalOrder`.

**Do not:** Call `$product->deductStock()` directly for a sale. All stock deductions from selling must go through a SaleOrder → Ship workflow. Do not skip the Confirm step — shipping without prior confirmation bypasses the stock hold and risks overselling.

## State Machine

```
Draft ──[Confirm]──> Confirmed ──[Pick]──> Picked ──[Ship]──> Shipped
  └──[Cancel]         └──[Cancel]           └──[Cancel]
         └──────────────────────────────────────> Cancelled

Confirm: reserve() per item — stores reservation_id on each line item
Ship:    fulfillReservation() per item
Cancel:  releaseReservation() per item (if reservations exist)
```

## Models

- `SaleOrder` — `HasFactory`, `SoftDeletes`; casts: `status => SaleOrderStatus`, `ordered_at`, `shipped_at` as datetime; relationships: `customer()`, `location()`, `items()`, `createdBy()`, `customerReturns()` morphMany ReturnOrder
- `SaleOrderItem` — relationships: `saleOrder()`, `product()`, `reservation()` belongsTo `Aldeebhasan\Inventorix\Models\Reservation`

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
| items | Repeater | relationship() — product_id (span 2), quantity (span 1), unit_price (span 1) |

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

**ConfirmAction** — `Draft → Confirmed` + reserve stock
```php
Action::make('confirm')
    ->visible(fn (SaleOrder $record) => $record->status === SaleOrderStatus::Draft)
    ->requiresConfirmation()
    ->action(function (SaleOrder $record) {
        DB::transaction(function () use ($record) {
            foreach ($record->items as $item) {
                $dto = new StockOperationDto(
                    transactionType: TransactionType::Sale,
                    causable: $record,
                    reference: $item,
                    createdBy: auth()->id(),
                );
                $reservation = $item->product->reserve($item->quantity, $record->location_id, $dto);
                $item->update(['reservation_id' => $reservation->id]);
            }
            $record->update(['status' => SaleOrderStatus::Confirmed]);
        });
    })
```

**PickAction** — `Confirmed → Picked` (no inventory call)
```php
Action::make('pick')
    ->visible(fn (SaleOrder $record) => $record->status === SaleOrderStatus::Confirmed)
    ->action(fn (SaleOrder $record) => $record->update(['status' => SaleOrderStatus::Picked]))
```

**ShipAction** — `Picked → Shipped` + fulfill reservations
```php
Action::make('ship')
    ->visible(fn (SaleOrder $record) => $record->status === SaleOrderStatus::Picked)
    ->requiresConfirmation()
    ->action(function (SaleOrder $record) {
        DB::transaction(function () use ($record) {
            foreach ($record->items as $item) {
                if ($item->reservation_id) {
                    $item->product->fulfillReservation($item->reservation_id);
                }
            }
            $record->update(['status' => SaleOrderStatus::Shipped, 'shipped_at' => now()]);
        });
    })
```

**CreateReturnAction** — visible only on Shipped orders; redirects to `CreateCustomerReturn` with `original_order_id` pre-filled
```php
Action::make('createReturn')
    ->label('Create Return')
    ->icon(Heroicon::OutlinedArrowUturnLeft)
    ->visible(fn (SaleOrder $record) => $record->status === SaleOrderStatus::Shipped)
    ->url(fn (SaleOrder $record) => CustomerReturnResource::getUrl('create', [
        'original_order_id' => $record->id,
        'customer_id' => $record->customer_id,
    ]))
```

**CancelAction** — any non-final status → Cancelled + release reservations
```php
Action::make('cancel')
    ->visible(fn (SaleOrder $record) => in_array($record->status, [
        SaleOrderStatus::Draft, SaleOrderStatus::Confirmed, SaleOrderStatus::Picked,
    ]))
    ->color('danger')
    ->requiresConfirmation()
    ->action(function (SaleOrder $record) {
        DB::transaction(function () use ($record) {
            foreach ($record->items as $item) {
                if ($item->reservation_id) {
                    $item->product->releaseReservation($item->reservation_id);
                    $item->update(['reservation_id' => null]);
                }
            }
            $record->update(['status' => SaleOrderStatus::Cancelled]);
        });
    })
```
