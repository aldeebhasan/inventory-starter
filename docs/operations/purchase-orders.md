# Purchase Orders

**Navigation Group:** Operations
**Purpose:** Receive goods from suppliers into a warehouse location.
**Referenced by:** Return Orders — a supplier return can reference a PO as its `originalOrder`.

**Do not:** Call `$product->addStock()` directly for a stock receipt. All stock additions from purchasing must go through a PurchaseOrder → Receive workflow so the movement is linked to the PO as `causable`.

---

## State Machine

```
Draft ──[Confirm]──> Confirmed ──[Receive]──> Received
  └──[Cancel]──> Cancelled      └──[Cancel]──> Cancelled
```

---

## Models

- `PurchaseOrder` — `HasFactory`, `SoftDeletes`; casts: `status => PurchaseOrderStatus`, `ordered_at`, `received_at` as datetime; relationships: `supplier()`, `location()`, `items()`, `createdBy()`, `returnOrders()` morphMany
- `PurchaseOrderItem` — relationships: `purchaseOrder()`, `product()`

---

## Filament Resource

**Path:** `app/Filament/Resources/PurchaseOrders/`
**Icon:** `Heroicon::OutlinedShoppingCart`

### Form (`Schemas/PurchaseOrderForm.php`)

| Field | Type | Notes |
|---|---|---|
| order_number | TextInput | auto-generated PO-XXXXX, unique |
| supplier_id | Select | relationship('supplier', 'name'), searchable, required |
| location_id | Select | relationship('location', 'name'), searchable, required |
| ordered_at | DateTimePicker | default now(), required |
| notes | Textarea | nullable |
| items | Repeater | relationship() — product_id (span 2), quantity (span 1), unit_cost (span 1) |

### Table (`Tables/PurchaseOrdersTable.php`)

| Column | Notes |
|---|---|
| order_number | sortable, searchable |
| supplier.name | sortable |
| location.name | |
| status | badge — gray/warning/success/danger |
| ordered_at | date, sortable |
| items_count | counts('items'), label 'Lines' |

Filters: `SelectFilter` on status, `SelectFilter` on supplier, `TrashedFilter`

### Pages

- `ListPurchaseOrders`, `CreatePurchaseOrder`, `ViewPurchaseOrder`, `EditPurchaseOrder` (Draft only)

### Workflow Actions (header actions on `ViewPurchaseOrder`)

**ConfirmAction** — `Draft → Confirmed`
```php
Action::make('confirm')
    ->visible(fn (PurchaseOrder $record) => $record->status === PurchaseOrderStatus::Draft)
    ->requiresConfirmation()
    ->action(fn (PurchaseOrder $record) => $record->update(['status' => PurchaseOrderStatus::Confirmed]))
```

**ReceiveAction** — `Confirmed → Received` + inventory
```php
Action::make('receive')
    ->visible(fn (PurchaseOrder $record) => $record->status === PurchaseOrderStatus::Confirmed)
    ->requiresConfirmation()
    ->action(function (PurchaseOrder $record) {
        DB::transaction(function () use ($record) {
            Inventorix::bulk(function (Transaction $tx) use ($record) {
                foreach ($record->items as $item) {
                    $dto = new StockOperationDto(
                        transaction: $tx,
                        transactionType: TransactionType::Purchase,
                        causable: $record,
                        reference: $item,
                        cost: $item->unit_cost,
                        createdBy: auth()->id(),
                    );
                    $item->product->addStock($item->quantity, $record->location_id, $dto);
                    $item->update(['received_quantity' => $item->quantity]);
                }
            });
            $record->update(['status' => PurchaseOrderStatus::Received, 'received_at' => now()]);
        });
    })
```

**CancelAction** — `Draft|Confirmed → Cancelled`
```php
Action::make('cancel')
    ->visible(fn (PurchaseOrder $record) => in_array($record->status, [
        PurchaseOrderStatus::Draft, PurchaseOrderStatus::Confirmed,
    ]))
    ->color('danger')
    ->requiresConfirmation()
    ->action(fn (PurchaseOrder $record) => $record->update(['status' => PurchaseOrderStatus::Cancelled]))
```
