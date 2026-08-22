<?php

use App\Enums\StockAdjustmentItemStatus;
use App\Enums\StockAdjustmentOperation;
use App\Enums\StockAdjustmentStatus;
use App\Filament\Resources\StockAdjustments\Pages\CreateStockAdjustment;
use App\Filament\Resources\StockAdjustments\Pages\EditStockAdjustment;
use App\Filament\Resources\StockAdjustments\Pages\ListStockAdjustments;
use App\Filament\Resources\StockAdjustments\Pages\ViewStockAdjustment;
use App\Jobs\ApplyStockAdjustmentJob;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentItem;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertSoftDeleted;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->location = createLocation();
});

// --- List ---

it('can render the stock adjustments list page', function () {
    Livewire::test(ListStockAdjustments::class)->assertSuccessful();
});

it('can list stock adjustments in the table', function () {
    $adjustments = StockAdjustment::factory()->count(3)->create([
        'location_id' => $this->location->id,
    ]);

    Livewire::test(ListStockAdjustments::class)
        ->assertCanSeeTableRecords($adjustments);
});

it('can filter stock adjustments by status', function () {
    $draft = StockAdjustment::factory()->create(['location_id' => $this->location->id]);
    $applied = StockAdjustment::factory()->applied()->create(['location_id' => $this->location->id]);

    Livewire::test(ListStockAdjustments::class)
        ->filterTable('status', StockAdjustmentStatus::Draft->value)
        ->assertCanSeeTableRecords([$draft])
        ->assertCanNotSeeTableRecords([$applied]);
});

// --- Create ---

it('can render the create stock adjustment page', function () {
    Livewire::test(CreateStockAdjustment::class)->assertSuccessful();
});

it('auto-generates order number on creation', function () {
    $adjustment = StockAdjustment::factory()->create(['location_id' => $this->location->id]);

    expect($adjustment->order_number)->toStartWith('ADJ-');
});

it('can create a stock adjustment', function () {
    Livewire::test(CreateStockAdjustment::class)
        ->fillForm([
            'location_id' => $this->location->id,
            'reason' => 'Annual stock count',
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    assertDatabaseHas(StockAdjustment::class, [
        'location_id' => $this->location->id,
        'reason' => 'Annual stock count',
        'status' => StockAdjustmentStatus::Draft,
    ]);
});

it('validates required fields on create', function () {
    Livewire::test(CreateStockAdjustment::class)
        ->fillForm(['location_id' => null, 'reason' => null])
        ->call('create')
        ->assertHasFormErrors(['location_id' => 'required', 'reason' => 'required']);
});

// --- View ---

it('can render the view stock adjustment page', function () {
    $adjustment = StockAdjustment::factory()->create(['location_id' => $this->location->id]);

    Livewire::test(ViewStockAdjustment::class, ['record' => $adjustment->id])
        ->assertSuccessful();
});

// --- Edit ---

it('can render the edit page for a draft adjustment', function () {
    $adjustment = StockAdjustment::factory()->create(['location_id' => $this->location->id]);

    Livewire::test(EditStockAdjustment::class, ['record' => $adjustment->id])
        ->assertSuccessful();
});

it('cannot edit a non-draft stock adjustment', function () {
    $adjustment = StockAdjustment::factory()->processing()->create(['location_id' => $this->location->id]);

    $this->get(route('filament.admin.resources.stock-adjustments.edit', $adjustment))
        ->assertForbidden();
});

// --- Soft Delete ---

it('can soft-delete a stock adjustment', function () {
    $adjustment = StockAdjustment::factory()->create(['location_id' => $this->location->id]);

    Livewire::test(ListStockAdjustments::class)
        ->callAction(TestAction::make('delete')->table($adjustment));

    assertSoftDeleted(StockAdjustment::class, ['id' => $adjustment->id]);
});

// --- Confirm action ---

it('confirm action snapshots current_stock and sets items to pending', function () {
    Queue::fake();

    $product = Product::factory()->create();
    $adjustment = StockAdjustment::factory()->create(['location_id' => $this->location->id]);
    $item = StockAdjustmentItem::factory()->create([
        'stock_adjustment_id' => $adjustment->id,
        'product_id' => $product->id,
        'operation' => StockAdjustmentOperation::Increase,
        'quantity' => 5,
    ]);

    Livewire::test(ViewStockAdjustment::class, ['record' => $adjustment->id])
        ->callAction('confirm');

    expect($item->fresh()->current_stock)->not->toBeNull();
    expect($item->fresh()->item_status)->toBe(StockAdjustmentItemStatus::Pending);
});

it('confirm action sets order status to Processing and dispatches the job', function () {
    Queue::fake();

    $product = Product::factory()->create();
    $adjustment = StockAdjustment::factory()->create(['location_id' => $this->location->id]);
    StockAdjustmentItem::factory()->create([
        'stock_adjustment_id' => $adjustment->id,
        'product_id' => $product->id,
        'operation' => StockAdjustmentOperation::Increase,
        'quantity' => 5,
    ]);

    Livewire::test(ViewStockAdjustment::class, ['record' => $adjustment->id])
        ->callAction('confirm');

    expect($adjustment->fresh()->status)->toBe(StockAdjustmentStatus::Processing);
    Queue::assertPushed(ApplyStockAdjustmentJob::class);
});

it('confirm is blocked when Decrease quantity exceeds available stock', function () {
    Queue::fake();

    $product = Product::factory()->create();
    $adjustment = StockAdjustment::factory()->create(['location_id' => $this->location->id]);
    StockAdjustmentItem::factory()->create([
        'stock_adjustment_id' => $adjustment->id,
        'product_id' => $product->id,
        'operation' => StockAdjustmentOperation::Decrease,
        'quantity' => 100,
    ]);

    Livewire::test(ViewStockAdjustment::class, ['record' => $adjustment->id])
        ->callAction('confirm');

    expect($adjustment->fresh()->status)->toBe(StockAdjustmentStatus::Draft);
    Queue::assertNothingPushed();
});

it('confirm allows Adjust with minimum quantity', function () {
    Queue::fake();

    $product = Product::factory()->create();
    $adjustment = StockAdjustment::factory()->create(['location_id' => $this->location->id]);
    StockAdjustmentItem::factory()->create([
        'stock_adjustment_id' => $adjustment->id,
        'product_id' => $product->id,
        'operation' => StockAdjustmentOperation::Adjust,
        'quantity' => 0.0001,
    ]);

    Livewire::test(ViewStockAdjustment::class, ['record' => $adjustment->id])
        ->callAction('confirm');

    expect($adjustment->fresh()->status)->toBe(StockAdjustmentStatus::Processing);
});

it('confirm is not visible on a Processing order', function () {
    $adjustment = StockAdjustment::factory()->processing()->create(['location_id' => $this->location->id]);

    Livewire::test(ViewStockAdjustment::class, ['record' => $adjustment->id])
        ->assertActionHidden('confirm');
});

// --- Job: ApplyStockAdjustmentJob ---

it('job applies Increase and sets item to Applied', function () {
    $product = Product::factory()->create();
    $adjustment = StockAdjustment::factory()->processing()->create(['location_id' => $this->location->id]);
    $item = StockAdjustmentItem::factory()->create([
        'stock_adjustment_id' => $adjustment->id,
        'product_id' => $product->id,
        'operation' => StockAdjustmentOperation::Increase,
        'quantity' => 10,
        'item_status' => StockAdjustmentItemStatus::Pending,
    ]);

    ApplyStockAdjustmentJob::dispatchSync($adjustment);

    expect($item->fresh()->item_status)->toBe(StockAdjustmentItemStatus::Applied);

    assertDatabaseHas('inventorix_stocks', [
        'stockable_type' => Product::class,
        'stockable_id' => $product->id,
        'location_id' => $this->location->id,
        'quantity' => 10,
    ]);
});

it('job applies Decrease and sets item to Applied', function () {
    $product = Product::factory()->create();
    $adjustment = StockAdjustment::factory()->processing()->create(['location_id' => $this->location->id]);

    // Seed stock first via a separate adjustment
    $seedAdj = StockAdjustment::factory()->processing()->create(['location_id' => $this->location->id]);
    $seedItem = StockAdjustmentItem::factory()->create([
        'stock_adjustment_id' => $seedAdj->id,
        'product_id' => $product->id,
        'operation' => StockAdjustmentOperation::Increase,
        'quantity' => 20,
        'item_status' => StockAdjustmentItemStatus::Pending,
    ]);
    ApplyStockAdjustmentJob::dispatchSync($seedAdj);

    $item = StockAdjustmentItem::factory()->create([
        'stock_adjustment_id' => $adjustment->id,
        'product_id' => $product->id,
        'operation' => StockAdjustmentOperation::Decrease,
        'quantity' => 8,
        'item_status' => StockAdjustmentItemStatus::Pending,
    ]);

    ApplyStockAdjustmentJob::dispatchSync($adjustment);

    expect($item->fresh()->item_status)->toBe(StockAdjustmentItemStatus::Applied);

    assertDatabaseHas('inventorix_stocks', [
        'stockable_type' => Product::class,
        'stockable_id' => $product->id,
        'location_id' => $this->location->id,
        'quantity' => 12,
    ]);
});

it('job applies Adjust and sets stock to exact quantity', function () {
    $product = Product::factory()->create();

    $seedAdj = StockAdjustment::factory()->processing()->create(['location_id' => $this->location->id]);
    StockAdjustmentItem::factory()->create([
        'stock_adjustment_id' => $seedAdj->id,
        'product_id' => $product->id,
        'operation' => StockAdjustmentOperation::Increase,
        'quantity' => 50,
        'item_status' => StockAdjustmentItemStatus::Pending,
    ]);
    ApplyStockAdjustmentJob::dispatchSync($seedAdj);

    $adjustment = StockAdjustment::factory()->processing()->create(['location_id' => $this->location->id]);
    $item = StockAdjustmentItem::factory()->create([
        'stock_adjustment_id' => $adjustment->id,
        'product_id' => $product->id,
        'operation' => StockAdjustmentOperation::Adjust,
        'quantity' => 30,
        'item_status' => StockAdjustmentItemStatus::Pending,
    ]);

    ApplyStockAdjustmentJob::dispatchSync($adjustment);

    expect($item->fresh()->item_status)->toBe(StockAdjustmentItemStatus::Applied);

    assertDatabaseHas('inventorix_stocks', [
        'stockable_type' => Product::class,
        'stockable_id' => $product->id,
        'location_id' => $this->location->id,
        'quantity' => 30,
    ]);
});

it('job marks a failing item as Failed and continues processing remaining items', function () {
    $goodProduct = Product::factory()->create();
    $badProduct = Product::factory()->create();
    $adjustment = StockAdjustment::factory()->processing()->create(['location_id' => $this->location->id]);

    $failingItem = StockAdjustmentItem::factory()->create([
        'stock_adjustment_id' => $adjustment->id,
        'product_id' => $badProduct->id,
        'operation' => StockAdjustmentOperation::Decrease,
        'quantity' => 999, // no stock — will fail
        'item_status' => StockAdjustmentItemStatus::Pending,
    ]);

    $goodItem = StockAdjustmentItem::factory()->create([
        'stock_adjustment_id' => $adjustment->id,
        'product_id' => $goodProduct->id,
        'operation' => StockAdjustmentOperation::Increase,
        'quantity' => 5,
        'item_status' => StockAdjustmentItemStatus::Pending,
    ]);

    ApplyStockAdjustmentJob::dispatchSync($adjustment);

    expect($failingItem->fresh()->item_status)->toBe(StockAdjustmentItemStatus::Failed);
    expect($failingItem->fresh()->failure_reason)->not->toBeNull();
    expect($goodItem->fresh()->item_status)->toBe(StockAdjustmentItemStatus::Applied);
    expect($adjustment->fresh()->status)->toBe(StockAdjustmentStatus::PartiallyApplied);
});

// --- syncStatusFromItems ---

it('syncStatusFromItems sets Applied when all items succeeded', function () {
    $adjustment = StockAdjustment::factory()->processing()->create(['location_id' => $this->location->id]);
    StockAdjustmentItem::factory()->count(2)->applied()->create([
        'stock_adjustment_id' => $adjustment->id,
    ]);

    $adjustment->syncStatusFromItems();

    expect($adjustment->fresh()->status)->toBe(StockAdjustmentStatus::Applied);
});

it('syncStatusFromItems sets PartiallyApplied when any item failed', function () {
    $adjustment = StockAdjustment::factory()->processing()->create(['location_id' => $this->location->id]);
    StockAdjustmentItem::factory()->applied()->create(['stock_adjustment_id' => $adjustment->id]);
    StockAdjustmentItem::factory()->failed()->create(['stock_adjustment_id' => $adjustment->id]);

    $adjustment->syncStatusFromItems();

    expect($adjustment->fresh()->status)->toBe(StockAdjustmentStatus::PartiallyApplied);
});

// --- Retry action ---

it('retry re-queues failed items and sets order back to Processing', function () {
    Queue::fake();

    $adjustment = StockAdjustment::factory()->partiallyApplied()->create(['location_id' => $this->location->id]);
    StockAdjustmentItem::factory()->applied()->create(['stock_adjustment_id' => $adjustment->id]);
    $failed = StockAdjustmentItem::factory()->failed()->create(['stock_adjustment_id' => $adjustment->id]);

    Livewire::test(ViewStockAdjustment::class, ['record' => $adjustment->id])
        ->callAction('retry');

    expect($adjustment->fresh()->status)->toBe(StockAdjustmentStatus::Processing);
    expect($failed->fresh()->item_status)->toBe(StockAdjustmentItemStatus::Pending);
    expect($failed->fresh()->failure_reason)->toBeNull();
    Queue::assertPushed(ApplyStockAdjustmentJob::class);
});

it('retry skips already Applied items', function () {
    Queue::fake();

    $adjustment = StockAdjustment::factory()->partiallyApplied()->create(['location_id' => $this->location->id]);
    $applied = StockAdjustmentItem::factory()->applied()->create(['stock_adjustment_id' => $adjustment->id]);
    StockAdjustmentItem::factory()->failed()->create(['stock_adjustment_id' => $adjustment->id]);

    Livewire::test(ViewStockAdjustment::class, ['record' => $adjustment->id])
        ->callAction('retry');

    expect($applied->fresh()->item_status)->toBe(StockAdjustmentItemStatus::Applied);
    Queue::assertPushed(ApplyStockAdjustmentJob::class);
});

it('retry is not visible on a Draft order', function () {
    $adjustment = StockAdjustment::factory()->create(['location_id' => $this->location->id]);

    Livewire::test(ViewStockAdjustment::class, ['record' => $adjustment->id])
        ->assertActionHidden('retry');
});

// --- Cancel action ---

it('can cancel a draft stock adjustment', function () {
    $adjustment = StockAdjustment::factory()->create(['location_id' => $this->location->id]);

    Livewire::test(ViewStockAdjustment::class, ['record' => $adjustment->id])
        ->callAction('cancel');

    expect($adjustment->fresh()->status)->toBe(StockAdjustmentStatus::Cancelled);
});

it('cancel is not visible on a Processing order', function () {
    $adjustment = StockAdjustment::factory()->processing()->create(['location_id' => $this->location->id]);

    Livewire::test(ViewStockAdjustment::class, ['record' => $adjustment->id])
        ->assertActionHidden('cancel');
});
