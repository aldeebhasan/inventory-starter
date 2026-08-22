# Adjustment Orders

**Navigation Group:** Inventory
**Purpose:** Manually correct stock levels for one or more products at a given location. Each line specifies an operation type — increase, decrease, or set. The order is processed asynchronously via a job batch so large documents don't block the UI. Each line tracks its own processing status, and failed lines can be retried without re-processing successful ones.

**Do not:** Call `$product->addStock()`, `$product->deductStock()`, or `$product->adjustStock()` directly for manual corrections. All manual stock changes must go through a StockAdjustment → Confirm workflow so the correction is documented with a reason and linked to an auditable document.

## State Machine

### Order Status

```
Draft ──[Confirm]──> Processing ──[all items Applied]──> Applied
  └──[Cancel]──> Cancelled   └──[any item Failed]──> PartiallyApplied
                                                            └──[Retry]──> Processing
```

- **Draft** — editable, can be cancelled.
- **Processing** — locked. Dispatched to queue. No actions available except viewing line statuses.
- **Applied** — all lines succeeded. Terminal state.
- **PartiallyApplied** — one or more lines failed. Retry available for failed lines only.
- **Cancelled** — terminal. Only allowed from Draft.

### Item Status

```
Pending ──[job picked up]──> Processing ──[success]──> Applied
                                        └──[exception]──> Failed (failure_reason stored)

Failed ──[Retry]──> Pending ──[job picked up]──> Processing ...
```

## Enums

### `StockAdjustmentOperation`

```php
enum StockAdjustmentOperation: string
{
    case Increase = 'increase';
    case Decrease = 'decrease';
    case Adjust   = 'adjust';
}
```

### `StockAdjustmentStatus`

```php
enum StockAdjustmentStatus: string
{
    case Draft            = 'draft';
    case Processing       = 'processing';
    case Applied          = 'applied';
    case PartiallyApplied = 'partially_applied';
    case Cancelled        = 'cancelled';
}
```

### `StockAdjustmentItemStatus`

```php
enum StockAdjustmentItemStatus: string
{
    case Pending    = 'pending';
    case Processing = 'processing';
    case Applied    = 'applied';
    case Failed     = 'failed';
}
```

## Models

- `StockAdjustment` — `HasFactory`, `SoftDeletes`; casts: `status => StockAdjustmentStatus`; relationships: `location()`, `items()`, `pendingItems()` (hasMany filtered by Pending), `failedItems()` (hasMany filtered by Failed), `createdBy()`
- `StockAdjustmentItem` — relationships: `stockAdjustment()`, `product()`; casts: `operation => StockAdjustmentOperation`, `item_status => StockAdjustmentItemStatus`

### Model Methods on `StockAdjustment`

```php
// Recompute order status from item statuses after batch finishes
public function syncStatusFromItems(): void
{
    $total  = $this->items()->count();
    $applied = $this->items()->where('item_status', StockAdjustmentItemStatus::Applied)->count();
    $failed  = $this->items()->where('item_status', StockAdjustmentItemStatus::Failed)->count();

    $this->update([
        'status' => match (true) {
            $applied === $total              => StockAdjustmentStatus::Applied,
            $failed > 0 && $applied > 0     => StockAdjustmentStatus::PartiallyApplied,
            $failed === $total               => StockAdjustmentStatus::PartiallyApplied,
            default                         => StockAdjustmentStatus::Processing,
        },
    ]);
}
```

### Migrations

**`stock_adjustments`**

| Column | Type | Notes |
|---|---|---|
| id | bigIncrements | |
| order_number | string | unique, auto-generated ADJ-XXXXX |
| location_id | unsignedBigInteger | FK → inventorix_locations.id |
| reason | string | required |
| notes | text | nullable |
| status | string | default 'draft' |
| created_by | unsignedBigInteger | nullable, FK → users.id |
| timestamps | | |
| softDeletes | | |

**`stock_adjustment_items`**

| Column | Type | Notes |
|---|---|---|
| id | bigIncrements | |
| stock_adjustment_id | unsignedBigInteger | FK → stock_adjustments.id, cascade delete |
| product_id | unsignedBigInteger | FK → products.id |
| operation | string | 'increase' / 'decrease' / 'adjust' |
| quantity | decimal(12,4) | must be > 0 |
| current_stock | decimal(12,4) | nullable — snapshotted at confirm time |
| item_status | string | default 'pending' |
| failure_reason | text | nullable — populated when item_status = 'failed' |
| timestamps | | |

> `current_stock` is snapshotted inside the Confirm action (within a DB transaction) before the job is dispatched, so the value reflects stock at decision time, not queue processing time.

## Quantity Validation (at Confirm time)

Validation runs before snapshotting. If any line fails, the whole Confirm is aborted with a notification listing the invalid lines. No status change occurs.

| Operation | Rule |
|---|---|
| Increase | `quantity > 0` |
| Decrease | `quantity > 0` AND `current_stock >= quantity` (cannot deduct more than available) |
| Adjust | `quantity >= 0` |

```php
$errors = [];
foreach ($record->items as $item) {
    $stock = $item->product->stockAt($record->location_id)?->quantity ?? 0;
    $valid = match ($item->operation) {
        StockAdjustmentOperation::Increase => $item->quantity > 0,
        StockAdjustmentOperation::Decrease => $item->quantity > 0 && $stock >= $item->quantity,
        StockAdjustmentOperation::Adjust   => $item->quantity >= 0,
    };
    if (! $valid) {
        $errors[] = "{$item->product->name}: insufficient stock ({$stock} available, {$item->quantity} requested)";
    }
}
if (! empty($errors)) {
    Notification::make()->danger()->title('Validation failed')->body(implode("\n", $errors))->send();
    $this->halt();
}
```

## Queue Architecture

A single `ApplyStockAdjustmentJob` handles all line items for the order. This keeps the queue simple (one job per confirm/retry) while still tracking per-line status.

### Job: `ApplyStockAdjustmentJob`

Processes all `Pending` items on the order sequentially. Each item is wrapped in its own try/catch so a failure on one line does not abort the rest.

```php
class ApplyStockAdjustmentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $backoff = 10; // seconds between retries

    public function __construct(public StockAdjustment $order) {}

    public function handle(): void
    {
        $items = $this->order->items()
            ->where('item_status', StockAdjustmentItemStatus::Pending)
            ->get();

        foreach ($items as $item) {
            $this->applyItem($item);
        }

        $this->order->syncStatusFromItems();
    }

    private function applyItem(StockAdjustmentItem $item): void
    {
        $item->update(['item_status' => StockAdjustmentItemStatus::Processing]);

        try {
            DB::transaction(function () use ($item) {
                $dto = new StockOperationDto(
                    transactionType: TransactionType::Adjustment,
                    causable: $this->order,
                    reference: $item,
                    note: "ADJ #{$this->order->order_number}: {$this->order->reason}",
                    createdBy: $this->order->created_by,
                );

                match ($item->operation) {
                    StockAdjustmentOperation::Increase => $item->product->addStock($item->quantity, $this->order->location_id, $dto),
                    StockAdjustmentOperation::Decrease => $item->product->deductStock($item->quantity, $this->order->location_id, $dto),
                    StockAdjustmentOperation::Adjust   => $item->product->adjustStock($item->quantity, $this->order->location_id, $dto),
                };
            });

            $item->update(['item_status' => StockAdjustmentItemStatus::Applied]);
        } catch (Throwable $e) {
            $item->update([
                'item_status'    => StockAdjustmentItemStatus::Failed,
                'failure_reason' => $e->getMessage(),
            ]);
        }
    }
}
```

Key properties:
- `$tries = 3` — the whole job retries up to 3 times on uncaught exceptions (e.g. DB connection loss). Per-item exceptions are caught internally and do not trigger a job retry.
- `$backoff = 10` — seconds between job-level retries.
- Only `Pending` items are processed, so a retry dispatch (which resets `Failed → Pending`) will only re-run the failed lines.

### Dispatching (in ConfirmAction and RetryAction)

```php
ApplyStockAdjustmentJob::dispatch($record->fresh())->onQueue('inventory');
```

Use a dedicated `adjustments` queue so large orders don't starve other queues.

## Filament Resource

**Path:** `app/Filament/Resources/StockAdjustments/`
**Icon:** `Heroicon::OutlinedAdjustmentsHorizontal`

### Form (`Schemas/StockAdjustmentForm.php`)

| Field | Type | Notes |
|---|---|---|
| order_number | TextInput | auto-generated ADJ-XXXXX, disabled on edit |
| location_id | Select | relationship('location', 'name'), searchable, required, live() |
| reason | TextInput | required |
| notes | Textarea | nullable |
| items | Repeater | relationship(), defaultItems(0) — see below |

Repeater items (Grid 4):

| Field | Span | Notes |
|---|---|---|
| product_id | 2 | Select, searchable, live(), required |
| operation | 1 | Select, options from StockAdjustmentOperation, required |
| quantity | 1 | TextInput numeric, required, min 0.0001 |

> `current_stock` and `item_status` are not part of the form — they are managed by the workflow, not the user.

### Table (`Tables/StockAdjustmentsTable.php`)

| Column | Notes |
|---|---|
| order_number | sortable, searchable |
| location.name | |
| reason | limit(60) |
| status | badge — gray(Draft) / primary(Processing) / success(Applied) / warning(PartiallyApplied) / danger(Cancelled) |
| items_count | counts('items'), label 'Lines' |
| created_at | date, sortable |

Filters: `SelectFilter` on status, `TrashedFilter`

Pages: `ListStockAdjustments`, `CreateStockAdjustment`, `ViewStockAdjustment`, `EditStockAdjustment` (Draft only)

### Workflow Actions (header actions on `ViewStockAdjustment`)

**ConfirmAction** — validate quantities → snapshot `current_stock` → dispatch batch → lock as `Processing`

```php
Action::make('confirm')
    ->visible(fn (StockAdjustment $record) => $record->status === StockAdjustmentStatus::Draft)
    ->requiresConfirmation()
    ->action(function (StockAdjustment $record, Action $action) {
        // 1. Snapshot current stock and validate
        $errors = [];
        DB::transaction(function () use ($record, &$errors) {
            foreach ($record->items as $item) {
                $stock = $item->product->stockAt($record->location_id)?->quantity ?? 0;
                $item->update(['current_stock' => $stock]);

                $valid = match ($item->operation) {
                    StockAdjustmentOperation::Increase => $item->quantity > 0,
                    StockAdjustmentOperation::Decrease => $item->quantity > 0 && $stock >= $item->quantity,
                    StockAdjustmentOperation::Adjust   => $item->quantity >= 0,
                };
                if (! $valid) {
                    $errors[] = "{$item->product->name}: insufficient stock ({$stock} available)";
                }
            }

            if (! empty($errors)) {
                return; // rollback snapshot, abort below
            }

            // 2. Lock the order
            $record->update(['status' => StockAdjustmentStatus::Processing]);
            $record->items()->update(['item_status' => StockAdjustmentItemStatus::Pending]);
        });

        if (! empty($errors)) {
            Notification::make()->danger()->title('Cannot confirm — validation failed')
                ->body(implode("\n", $errors))->send();
            $action->halt();
            return;
        }

        // 3. Dispatch batch (outside transaction so DB state is visible to workers)
        $jobs = $record->items->map(fn ($item) => new ApplyStockAdjustmentItemJob($item->fresh()));

        Bus::batch($jobs)
            ->finally(fn (Batch $batch) => StockAdjustment::find($record->id)->syncStatusFromItems())
            ->onQueue('inventory')
            ->dispatch();

        Notification::make()->success()->title('Order confirmed and queued for processing')->send();
    })
```

**RetryAction** — re-queue only `Failed` items → back to `Processing`

```php
Action::make('retry')
    ->visible(fn (StockAdjustment $record) => $record->status === StockAdjustmentStatus::PartiallyApplied)
    ->requiresConfirmation()
    ->color('warning')
    ->action(function (StockAdjustment $record) {
        $failedItems = $record->items()->where('item_status', StockAdjustmentItemStatus::Failed)->get();

        $failedItems->each->update([
            'item_status'    => StockAdjustmentItemStatus::Pending,
            'failure_reason' => null,
        ]);

        $record->update(['status' => StockAdjustmentStatus::Processing]);

        $jobs = $failedItems->map(fn ($item) => new ApplyStockAdjustmentItemJob($item));

        Bus::batch($jobs)
            ->finally(fn (Batch $batch) => StockAdjustment::find($record->id)->syncStatusFromItems())
            ->onQueue('inventory')
            ->dispatch();

        Notification::make()->success()->title('Failed lines re-queued')->send();
    })
```

**CancelAction** — `Draft → Cancelled` only (cannot cancel once dispatched)

```php
Action::make('cancel')
    ->visible(fn (StockAdjustment $record) => $record->status === StockAdjustmentStatus::Draft)
    ->color('danger')
    ->requiresConfirmation()
    ->action(fn (StockAdjustment $record) => $record->update(['status' => StockAdjustmentStatus::Cancelled]))
```

## View Page Infolist

Display on `ViewStockAdjustment`:

- Header section: `order_number`, `status` badge, `location.name`, `reason`, `notes`, `created_at`
- Items section (RepeatableEntry or custom Blade table, auto-polls when `status = Processing`):

| Column | Notes |
|---|---|
| product.name | |
| operation | badge — success(Increase) / danger(Decrease) / warning(Adjust) |
| current_stock | snapshot, shown after Confirm |
| quantity | the adjustment value |
| item_status | badge — gray(Pending) / primary(Processing) / success(Applied) / danger(Failed) |
| failure_reason | shown only when item_status = Failed |

> Add `wire:poll.3000ms` to the view page when the order is in `Processing` status so item statuses update without a manual refresh.

## Auto-generate `order_number`

In `StockAdjustment::booted()`:

```php
static::creating(function (StockAdjustment $model) {
    if (empty($model->order_number)) {
        $model->order_number = 'ADJ-' . str_pad(
            (static::withTrashed()->max('id') ?? 0) + 1,
            5, '0', STR_PAD_LEFT
        );
    }
});
```

## Testing Requirements

File: `tests/Feature/Inventory/StockAdjustmentResourceTest.php`

Use `Queue::fake()` in workflow tests to assert the job is dispatched without running it.
Use `ApplyStockAdjustmentJob::dispatchSync($order)` in stock operation tests to run the job inline.

| Test | Covers |
|---|---|
| renders list page | ListStockAdjustments loads |
| lists adjustments | table shows records |
| renders create page | CreateStockAdjustment loads |
| creates adjustment with items | form save, DB assertions on both tables |
| validates required fields | location_id, reason |
| renders view page | ViewStockAdjustment loads |
| confirm snapshots current_stock per item | items.current_stock populated in DB |
| confirm dispatches job | Queue::assertPushed(ApplyStockAdjustmentJob::class) |
| confirm sets order to Processing | order.status = processing |
| confirm sets item_status to Pending | all items pending after confirm |
| confirm blocks Decrease when stock insufficient | error notification, status stays Draft, nothing pushed |
| confirm allows Adjust with minimum quantity | valid, no error |
| job applies Increase correctly | product stock increases by quantity, item Applied |
| job applies Decrease correctly | product stock decreases by quantity, item Applied |
| job applies Adjust correctly | product stock set to quantity, item Applied |
| job marks failing item as Failed and continues | failed item = Failed + reason, good item = Applied, order = PartiallyApplied |
| syncStatusFromItems sets Applied | all items Applied → order Applied |
| syncStatusFromItems sets PartiallyApplied | any item Failed → order PartiallyApplied |
| retry action re-queues failed items | Failed → Pending, order → Processing, job dispatched |
| retry skips already Applied items | Applied items untouched |
| cancel from draft | Draft → Cancelled |
| cancel not available when Processing | action hidden |
| edit page loads for draft | EditStockAdjustment accessible |
| edit page not available for Processing | 403 |
| soft delete | TrashedFilter shows deleted records |
