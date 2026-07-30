# Return Orders

**Navigation Group:** Operations
**Purpose:** Two return scenarios:
- **Customer Return** — goods come back from customer → `addStock()`
- **Supplier Return** — goods sent back to supplier → `deductStock()`

**References:** Sale Orders or Purchase Orders via `originalOrder()` morphTo (`original_order_type` / `original_order_id`).

**Do not:** Mix up the two return types. CustomerReturn adds stock (reversing a sale); SupplierReturn removes stock (reversing a purchase). Using the wrong type silently corrupts stock levels.

---

## State Machine

```
Draft ──[Approve]──> Approved ──[Complete]──> Completed
  └──[Cancel]──> Cancelled      └──[Cancel]──> Cancelled

Complete (CustomerReturn): addStock() per item
Complete (SupplierReturn): deductStock() per item
```

---

## Models

- `ReturnOrder` — `HasFactory`, `SoftDeletes`; casts: `type => ReturnOrderType`, `status => ReturnOrderStatus`; relationships: `originalOrder()` morphTo (via `original_order_type` / `original_order_id`), `customer()` nullable, `supplier()` nullable, `location()`, `items()`, `createdBy()`
- `ReturnOrderItem` — relationships: `returnOrder()`, `product()`

---

## Filament Resource

**Path:** `app/Filament/Resources/ReturnOrders/`
**Icon:** `Heroicon::OutlinedArrowUturnLeft`

### Form (`Schemas/ReturnOrderForm.php`)

| Field | Type | Notes |
|---|---|---|
| order_number | TextInput | auto-generated RET-XXXXX, unique |
| type | Select | options: ReturnOrderType, required, live() |
| customer_id | Select | visible when type = CustomerReturn |
| supplier_id | Select | visible when type = SupplierReturn |
| location_id | Select | relationship('location', 'name'), required |
| reason | TextInput | required |
| notes | Textarea | nullable |
| items | Repeater | relationship() — product_id (span 2), quantity (span 1), unit_cost nullable (span 1) |

Conditional visibility:
```php
Select::make('customer_id')
    ->visible(fn (Get $get) => $get('type') === ReturnOrderType::CustomerReturn->value),
Select::make('supplier_id')
    ->visible(fn (Get $get) => $get('type') === ReturnOrderType::SupplierReturn->value),
```

### Table (`Tables/ReturnOrdersTable.php`)

| Column | Notes |
|---|---|
| order_number | sortable, searchable |
| type | badge |
| customer.name / supplier.name | computed label 'Party' |
| status | badge |
| created_at | date, sortable |

Filters: `SelectFilter` on type, `SelectFilter` on status, `TrashedFilter`

### Pages

- `ListReturnOrders`, `CreateReturnOrder`, `ViewReturnOrder`, `EditReturnOrder` (Draft only)

### Workflow Actions (header actions on `ViewReturnOrder`)

**ApproveAction** — `Draft → Approved`
```php
Action::make('approve')
    ->visible(fn (ReturnOrder $record) => $record->status === ReturnOrderStatus::Draft)
    ->requiresConfirmation()
    ->action(fn (ReturnOrder $record) => $record->update(['status' => ReturnOrderStatus::Approved]))
```

**CompleteAction** — `Approved → Completed` + inventory
```php
Action::make('complete')
    ->visible(fn (ReturnOrder $record) => $record->status === ReturnOrderStatus::Approved)
    ->requiresConfirmation()
    ->action(function (ReturnOrder $record) {
        DB::transaction(function () use ($record) {
            $transactionType = $record->type === ReturnOrderType::CustomerReturn
                ? TransactionType::Sale
                : TransactionType::Purchase;

            Inventorix::bulk(function (Transaction $tx) use ($record, $transactionType) {
                foreach ($record->items as $item) {
                    $dto = new StockOperationDto(
                        transaction: $tx,
                        transactionType: $transactionType,
                        causable: $record,
                        reference: $item,
                        cost: $item->unit_cost,
                        createdBy: auth()->id(),
                    );
                    if ($record->type === ReturnOrderType::CustomerReturn) {
                        $item->product->addStock($item->quantity, $record->location_id, $dto);
                    } else {
                        $item->product->deductStock($item->quantity, $record->location_id, $dto);
                    }
                }
            });
            $record->update(['status' => ReturnOrderStatus::Completed]);
        });
    })
```

**CancelAction** — `Draft|Approved → Cancelled`
