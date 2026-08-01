# Adjustment Orders

**Navigation Group:** Inventory
**Purpose:** Reconcile physical stock count against system stock count for a location.

**Do not:** Call `$product->adjustStock()` directly. All stock reconciliations must go through an AdjustmentOrder → Apply workflow so the correction is documented with a reason and linked to an auditable document.

## State Machine

```
Draft ──[Confirm]──> Confirmed ──[Apply]──> Applied
  └──[Cancel]──> Cancelled       └──[Cancel]──> Cancelled

Apply: adjustStock(actual_quantity) per item via Inventorix::bulk()
```

## Models

- `AdjustmentOrder` — `HasFactory`, `SoftDeletes`; cast: `status => AdjustmentOrderStatus`; relationships: `location()`, `items()`, `createdBy()`
- `AdjustmentOrderItem` — relationships: `adjustmentOrder()`, `product()`; computed `variance(): float` → `actual_quantity - expected_quantity`

## Filament Resource

**Path:** `app/Filament/Resources/AdjustmentOrders/`
**Icon:** `Heroicon::OutlinedAdjustmentsHorizontal`

### Form (`Schemas/AdjustmentOrderForm.php`)

| Field | Type | Notes |
|---|---|---|
| order_number | TextInput | auto-generated ADJ-XXXXX, unique |
| location_id | Select | relationship('location', 'name'), required, live() |
| reason | TextInput | required |
| notes | Textarea | nullable |
| items | Repeater | relationship() — see below |

Repeater items:
- `product_id` — Select, `->live()->afterStateUpdated()` auto-fills `expected_quantity`
- `expected_quantity` — TextInput, disabled (display only — current stock from `stockAt()`)
- `actual_quantity` — TextInput numeric, required

Auto-fill on `product_id` change:
```php
->afterStateUpdated(function (Set $set, ?string $state, Get $get) {
    $locationId = $get('../../location_id');
    $stock = $state && $locationId
        ? Product::find($state)?->stockAt($locationId)?->quantity ?? 0
        : 0;
    $set('expected_quantity', $stock);
})
```

### Table (`Tables/AdjustmentOrdersTable.php`)

| Column | Notes |
|---|---|
| order_number | sortable, searchable |
| location.name | |
| reason | limit(50) |
| status | badge |
| created_at | date, sortable |

Filters: `SelectFilter` on status, `TrashedFilter`

Pages: `ListAdjustmentOrders`, `CreateAdjustmentOrder`, `ViewAdjustmentOrder`, `EditAdjustmentOrder` (Draft only)

### Workflow Actions (header actions on `ViewAdjustmentOrder`)

**ConfirmAction** — `Draft → Confirmed`

**ApplyAction** — `Confirmed → Applied` + adjust stock
```php
Action::make('apply')
    ->visible(fn (AdjustmentOrder $record) => $record->status === AdjustmentOrderStatus::Confirmed)
    ->requiresConfirmation()
    ->action(function (AdjustmentOrder $record) {
        DB::transaction(function () use ($record) {
            Inventorix::bulk(function (Transaction $tx) use ($record) {
                foreach ($record->items as $item) {
                    $dto = new StockOperationDto(
                        transaction: $tx,
                        transactionType: TransactionType::Adjustment,
                        causable: $record,
                        reference: $item,
                        note: "ADJ #{$record->order_number}: {$record->reason}",
                        createdBy: auth()->id(),
                    );
                    $item->product->adjustStock($item->actual_quantity, $record->location_id, $dto);
                }
            });
            $record->update(['status' => AdjustmentOrderStatus::Applied]);
        });
    })
```

**CancelAction** — `Draft|Confirmed → Cancelled`
