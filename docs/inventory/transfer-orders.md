# Transfer Orders

**Navigation Group:** Inventory
**Purpose:** Move stock between two warehouse locations.

**Do not:** Call `$product->transferStock()` directly. All location-to-location moves must go through a TransferOrder → Complete workflow so the movement is auditable and linked to a document.

## State Machine

```
Draft ──[Confirm]──> Confirmed ──[Complete]──> Completed
  └──[Cancel]──> Cancelled       └──[Cancel]──> Cancelled

Complete: transferStock() per item via Inventorix::bulk()
```

## Models

- `TransferOrder` — `HasFactory`, `SoftDeletes`; cast: `status => TransferOrderStatus`; relationships: `fromLocation()` (FK: from_location_id), `toLocation()` (FK: to_location_id), `items()`, `createdBy()`
- `TransferOrderItem` — relationships: `transferOrder()`, `product()`

## Filament Resource

**Path:** `app/Filament/Resources/TransferOrders/`
**Icon:** `Heroicon::OutlinedArrowsRightLeft`

### Form (`Schemas/TransferOrderForm.php`)

| Field | Type | Notes |
|---|---|---|
| order_number | TextInput | auto-generated TO-XXXXX, unique |
| from_location_id | Select | relationship('fromLocation', 'name'), required |
| to_location_id | Select | relationship('toLocation', 'name'), required |
| notes | Textarea | nullable |
| items | Repeater | relationship() — product_id, quantity |

### Table (`Tables/TransferOrdersTable.php`)

| Column | Notes |
|---|---|
| order_number | sortable, searchable |
| fromLocation.name | label 'From' |
| toLocation.name | label 'To' |
| status | badge |
| created_at | date, sortable |

Filters: `SelectFilter` on status, `TrashedFilter`

Pages: `ListTransferOrders`, `CreateTransferOrder`, `ViewTransferOrder`, `EditTransferOrder` (Draft only)

### Workflow Actions (header actions on `ViewTransferOrder`)

**ConfirmAction** — `Draft → Confirmed`

**CompleteAction** — `Confirmed → Completed` + transfer stock
```php
Action::make('complete')
    ->visible(fn (TransferOrder $record) => $record->status === TransferOrderStatus::Confirmed)
    ->requiresConfirmation()
    ->action(function (TransferOrder $record) {
        DB::transaction(function () use ($record) {
            Inventorix::bulk(function (Transaction $tx) use ($record) {
                foreach ($record->items as $item) {
                    $dto = new StockOperationDto(
                        transaction: $tx,
                        transactionType: TransactionType::Transfer,
                        causable: $record,
                        reference: $item,
                        createdBy: auth()->id(),
                    );
                    $item->product->transferStock(
                        $item->quantity,
                        $record->from_location_id,
                        $record->to_location_id,
                        $dto
                    );
                }
            });
            $record->update(['status' => TransferOrderStatus::Completed]);
        });
    })
```

**CancelAction** — `Draft|Confirmed → Cancelled`
