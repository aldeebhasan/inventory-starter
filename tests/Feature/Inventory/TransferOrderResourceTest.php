<?php

use App\Enums\TransferOrderItemStatus;
use App\Enums\TransferOrderStatus;
use App\Filament\Resources\TransferOrders\Pages\CreateTransferOrder;
use App\Filament\Resources\TransferOrders\Pages\EditTransferOrder;
use App\Filament\Resources\TransferOrders\Pages\ListTransferOrders;
use App\Filament\Resources\TransferOrders\Pages\ViewTransferOrder;
use App\Jobs\DirectTransferItemsJob;
use App\Jobs\ReceiveTransferItemsJob;
use App\Jobs\SendTransferItemsJob;
use App\Models\Product;
use App\Models\TransferOrder;
use App\Models\TransferOrderItem;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertSoftDeleted;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->fromLocation = createLocation(['name' => 'Source Warehouse']);
    $this->toLocation = createLocation(['name' => 'Destination Warehouse']);
});

// --- List ---

it('can render the transfer orders list page', function () {
    Livewire::test(ListTransferOrders::class)->assertSuccessful();
});

it('can list transfer orders in the table', function () {
    $orders = TransferOrder::factory()->count(3)->create([
        'from_location_id' => $this->fromLocation->id,
        'to_location_id' => $this->toLocation->id,
    ]);

    Livewire::test(ListTransferOrders::class)
        ->assertCanSeeTableRecords($orders);
});

it('can filter transfer orders by status', function () {
    $draft = TransferOrder::factory()->create([
        'from_location_id' => $this->fromLocation->id,
        'to_location_id' => $this->toLocation->id,
    ]);
    $completed = TransferOrder::factory()->completed()->create([
        'from_location_id' => $this->fromLocation->id,
        'to_location_id' => $this->toLocation->id,
    ]);

    Livewire::test(ListTransferOrders::class)
        ->filterTable('status', TransferOrderStatus::Draft->value)
        ->assertCanSeeTableRecords([$draft])
        ->assertCanNotSeeTableRecords([$completed]);
});

// --- Create ---

it('can render the create transfer order page', function () {
    Livewire::test(CreateTransferOrder::class)->assertSuccessful();
});

it('auto-generates order number on creation', function () {
    $order = TransferOrder::factory()->create([
        'from_location_id' => $this->fromLocation->id,
        'to_location_id' => $this->toLocation->id,
    ]);

    expect($order->order_number)->toStartWith('TO-');
});

it('can create a transfer order with items', function () {
    $product = Product::factory()->create();

    Livewire::test(CreateTransferOrder::class)
        ->fillForm([
            'from_location_id' => $this->fromLocation->id,
            'to_location_id' => $this->toLocation->id,
            'items' => [
                ['product_id' => $product->id, 'quantity' => 10],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    assertDatabaseHas(TransferOrder::class, [
        'from_location_id' => $this->fromLocation->id,
        'to_location_id' => $this->toLocation->id,
        'status' => TransferOrderStatus::Draft,
    ]);

    assertDatabaseHas(TransferOrderItem::class, [
        'product_id' => $product->id,
        'quantity' => 10,
    ]);
});

it('validates required fields on create', function () {
    Livewire::test(CreateTransferOrder::class)
        ->fillForm(['from_location_id' => null, 'to_location_id' => null])
        ->call('create')
        ->assertHasFormErrors(['from_location_id' => 'required', 'to_location_id' => 'required']);
});

it('validates from and to locations are different', function () {
    Livewire::test(CreateTransferOrder::class)
        ->fillForm([
            'from_location_id' => $this->fromLocation->id,
            'to_location_id' => $this->fromLocation->id,
        ])
        ->call('create')
        ->assertHasFormErrors(['to_location_id' => 'different']);
});

// --- View ---

it('can render the view transfer order page', function () {
    $order = TransferOrder::factory()->create([
        'from_location_id' => $this->fromLocation->id,
        'to_location_id' => $this->toLocation->id,
    ]);

    Livewire::test(ViewTransferOrder::class, ['record' => $order->id])
        ->assertSuccessful();
});

// --- Edit ---

it('can render the edit page for a draft transfer order', function () {
    $order = TransferOrder::factory()->create([
        'from_location_id' => $this->fromLocation->id,
        'to_location_id' => $this->toLocation->id,
    ]);

    Livewire::test(EditTransferOrder::class, ['record' => $order->id])
        ->assertSuccessful();
});

it('cannot edit a non-draft transfer order', function () {
    $order = TransferOrder::factory()->confirmed()->create([
        'from_location_id' => $this->fromLocation->id,
        'to_location_id' => $this->toLocation->id,
    ]);

    $this->get(route('filament.admin.resources.transfer-orders.edit', $order))
        ->assertForbidden();
});

// --- Soft Delete ---

it('can soft-delete a transfer order', function () {
    $order = TransferOrder::factory()->create([
        'from_location_id' => $this->fromLocation->id,
        'to_location_id' => $this->toLocation->id,
    ]);

    Livewire::test(ListTransferOrders::class)
        ->callAction(TestAction::make('delete')->table($order));

    assertSoftDeleted(TransferOrder::class, ['id' => $order->id]);
});

// --- Confirm action ---

it('confirm action validates stock sufficiency', function () {
    Queue::fake();

    $product = Product::factory()->create();
    $order = TransferOrder::factory()->create([
        'from_location_id' => $this->fromLocation->id,
        'to_location_id' => $this->toLocation->id,
    ]);
    TransferOrderItem::factory()->create([
        'transfer_order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 100, // no stock available
    ]);

    Livewire::test(ViewTransferOrder::class, ['record' => $order->id])
        ->callAction('confirm');

    expect($order->fresh()->status)->toBe(TransferOrderStatus::Draft);
    Queue::assertNothingPushed();
});

it('confirm action locks order and sets items to pending', function () {
    $product = Product::factory()->create();
    // Seed stock at source
    $product->addStock(50, $this->fromLocation->id);

    $order = TransferOrder::factory()->create([
        'from_location_id' => $this->fromLocation->id,
        'to_location_id' => $this->toLocation->id,
    ]);
    $item = TransferOrderItem::factory()->create([
        'transfer_order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 10,
    ]);

    Livewire::test(ViewTransferOrder::class, ['record' => $order->id])
        ->callAction('confirm');

    expect($order->fresh()->status)->toBe(TransferOrderStatus::Confirmed);
    expect($item->fresh()->item_status)->toBe(TransferOrderItemStatus::Pending);
});

// --- Send action ---

it('send action dispatches job and sets order to Sending', function () {
    Queue::fake();

    $order = TransferOrder::factory()->confirmed()->create([
        'from_location_id' => $this->fromLocation->id,
        'to_location_id' => $this->toLocation->id,
    ]);
    TransferOrderItem::factory()->create([
        'transfer_order_id' => $order->id,
        'item_status' => TransferOrderItemStatus::Pending,
    ]);

    Livewire::test(ViewTransferOrder::class, ['record' => $order->id])
        ->callAction('send');

    expect($order->fresh()->status)->toBe(TransferOrderStatus::Sending);
    Queue::assertPushed(SendTransferItemsJob::class);
});

it('send action is hidden when not Confirmed', function () {
    $order = TransferOrder::factory()->create([
        'from_location_id' => $this->fromLocation->id,
        'to_location_id' => $this->toLocation->id,
    ]);

    Livewire::test(ViewTransferOrder::class, ['record' => $order->id])
        ->assertActionHidden('send');
});

// --- Direct Transfer (Complete action) ---

it('complete action dispatches DirectTransferItemsJob', function () {
    Queue::fake();

    $order = TransferOrder::factory()->confirmed()->create([
        'from_location_id' => $this->fromLocation->id,
        'to_location_id' => $this->toLocation->id,
    ]);
    TransferOrderItem::factory()->create([
        'transfer_order_id' => $order->id,
        'item_status' => TransferOrderItemStatus::Pending,
    ]);

    Livewire::test(ViewTransferOrder::class, ['record' => $order->id])
        ->callAction('complete');

    expect($order->fresh()->status)->toBe(TransferOrderStatus::Sending);
    Queue::assertPushed(DirectTransferItemsJob::class);
});

it('direct transfer job deducts from source and adds to destination', function () {
    $product = Product::factory()->create();
    $product->addStock(50, $this->fromLocation->id);

    $order = TransferOrder::factory()->sending()->create([
        'from_location_id' => $this->fromLocation->id,
        'to_location_id' => $this->toLocation->id,
    ]);
    $item = TransferOrderItem::factory()->create([
        'transfer_order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 20,
        'item_status' => TransferOrderItemStatus::Pending,
    ]);

    DirectTransferItemsJob::dispatchSync($order);

    expect($order->fresh()->status)->toBe(TransferOrderStatus::Completed);
    expect($item->fresh()->item_status)->toBe(TransferOrderItemStatus::Received);

    assertDatabaseHas('inventorix_stocks', [
        'stockable_type' => Product::class,
        'stockable_id' => $product->id,
        'location_id' => $this->fromLocation->id,
        'quantity' => 30,
    ]);

    assertDatabaseHas('inventorix_stocks', [
        'stockable_type' => Product::class,
        'stockable_id' => $product->id,
        'location_id' => $this->toLocation->id,
        'quantity' => 20,
    ]);
});

it('direct transfer job handles partial failure', function () {
    $goodProduct = Product::factory()->create();
    $goodProduct->addStock(50, $this->fromLocation->id);

    $badProduct = Product::factory()->create();

    $order = TransferOrder::factory()->sending()->create([
        'from_location_id' => $this->fromLocation->id,
        'to_location_id' => $this->toLocation->id,
    ]);

    $goodItem = TransferOrderItem::factory()->create([
        'transfer_order_id' => $order->id,
        'product_id' => $goodProduct->id,
        'quantity' => 10,
        'item_status' => TransferOrderItemStatus::Pending,
    ]);

    $badItem = TransferOrderItem::factory()->create([
        'transfer_order_id' => $order->id,
        'product_id' => $badProduct->id,
        'quantity' => 999,
        'item_status' => TransferOrderItemStatus::Pending,
    ]);

    DirectTransferItemsJob::dispatchSync($order);

    expect($goodItem->fresh()->item_status)->toBe(TransferOrderItemStatus::Received);
    expect($badItem->fresh()->item_status)->toBe(TransferOrderItemStatus::Failed);
    expect($badItem->fresh()->failure_reason)->not->toBeNull();
    expect($order->fresh()->status)->toBe(TransferOrderStatus::PartiallySent);
});

it('direct transfer is hidden when not Confirmed', function () {
    $order = TransferOrder::factory()->create([
        'from_location_id' => $this->fromLocation->id,
        'to_location_id' => $this->toLocation->id,
    ]);

    Livewire::test(ViewTransferOrder::class, ['record' => $order->id])
        ->assertActionHidden('complete');
});

// --- SendTransferItemsJob ---

it('send job deducts stock from source and marks items as Sent', function () {
    $product = Product::factory()->create();
    $product->addStock(50, $this->fromLocation->id);

    $order = TransferOrder::factory()->sending()->create([
        'from_location_id' => $this->fromLocation->id,
        'to_location_id' => $this->toLocation->id,
    ]);
    $item = TransferOrderItem::factory()->create([
        'transfer_order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 20,
        'item_status' => TransferOrderItemStatus::Pending,
    ]);

    SendTransferItemsJob::dispatchSync($order);

    expect($item->fresh()->item_status)->toBe(TransferOrderItemStatus::Sent);
    expect($order->fresh()->status)->toBe(TransferOrderStatus::InTransit);

    assertDatabaseHas('inventorix_stocks', [
        'stockable_type' => Product::class,
        'stockable_id' => $product->id,
        'location_id' => $this->fromLocation->id,
        'quantity' => 30,
    ]);
});

it('send job isolates failures per item', function () {
    $goodProduct = Product::factory()->create();
    $goodProduct->addStock(50, $this->fromLocation->id);

    $badProduct = Product::factory()->create();
    // No stock for bad product

    $order = TransferOrder::factory()->sending()->create([
        'from_location_id' => $this->fromLocation->id,
        'to_location_id' => $this->toLocation->id,
    ]);

    $goodItem = TransferOrderItem::factory()->create([
        'transfer_order_id' => $order->id,
        'product_id' => $goodProduct->id,
        'quantity' => 10,
        'item_status' => TransferOrderItemStatus::Pending,
    ]);

    $badItem = TransferOrderItem::factory()->create([
        'transfer_order_id' => $order->id,
        'product_id' => $badProduct->id,
        'quantity' => 999,
        'item_status' => TransferOrderItemStatus::Pending,
    ]);

    SendTransferItemsJob::dispatchSync($order);

    expect($goodItem->fresh()->item_status)->toBe(TransferOrderItemStatus::Sent);
    expect($badItem->fresh()->item_status)->toBe(TransferOrderItemStatus::Failed);
    expect($badItem->fresh()->failure_reason)->not->toBeNull();
    expect($order->fresh()->status)->toBe(TransferOrderStatus::PartiallySent);
});

// --- Retry Send ---

it('retry send re-queues failed items', function () {
    Queue::fake();

    $order = TransferOrder::factory()->create([
        'from_location_id' => $this->fromLocation->id,
        'to_location_id' => $this->toLocation->id,
        'status' => TransferOrderStatus::PartiallySent,
    ]);

    $sentItem = TransferOrderItem::factory()->sent()->create(['transfer_order_id' => $order->id]);
    $failedItem = TransferOrderItem::factory()->failed()->create(['transfer_order_id' => $order->id]);

    Livewire::test(ViewTransferOrder::class, ['record' => $order->id])
        ->callAction('retry_send');

    expect($order->fresh()->status)->toBe(TransferOrderStatus::Sending);
    expect($failedItem->fresh()->item_status)->toBe(TransferOrderItemStatus::Pending);
    expect($failedItem->fresh()->failure_reason)->toBeNull();
    expect($sentItem->fresh()->item_status)->toBe(TransferOrderItemStatus::Sent);
    Queue::assertPushed(SendTransferItemsJob::class);
});

// --- Receive action ---

it('receive action dispatches job and sets order to Receiving', function () {
    Queue::fake();

    $order = TransferOrder::factory()->inTransit()->create([
        'from_location_id' => $this->fromLocation->id,
        'to_location_id' => $this->toLocation->id,
    ]);
    TransferOrderItem::factory()->sent()->create(['transfer_order_id' => $order->id]);

    Livewire::test(ViewTransferOrder::class, ['record' => $order->id])
        ->callAction('receive');

    expect($order->fresh()->status)->toBe(TransferOrderStatus::Receiving);
    Queue::assertPushed(ReceiveTransferItemsJob::class);
});

it('receive action is hidden when not InTransit', function () {
    $order = TransferOrder::factory()->confirmed()->create([
        'from_location_id' => $this->fromLocation->id,
        'to_location_id' => $this->toLocation->id,
    ]);

    Livewire::test(ViewTransferOrder::class, ['record' => $order->id])
        ->assertActionHidden('receive');
});

// --- ReceiveTransferItemsJob ---

it('receive job adds stock at destination and marks items as Received', function () {
    $product = Product::factory()->create();
    $product->addStock(50, $this->fromLocation->id);

    $order = TransferOrder::factory()->sending()->create([
        'from_location_id' => $this->fromLocation->id,
        'to_location_id' => $this->toLocation->id,
    ]);
    $item = TransferOrderItem::factory()->create([
        'transfer_order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 15,
        'item_status' => TransferOrderItemStatus::Pending,
    ]);

    // Send first to create the pending transaction
    SendTransferItemsJob::dispatchSync($order);
    expect($item->fresh()->item_status)->toBe(TransferOrderItemStatus::Sent);

    // Now receive
    $order->update(['status' => TransferOrderStatus::Receiving]);
    ReceiveTransferItemsJob::dispatchSync($order->fresh());

    expect($item->fresh()->item_status)->toBe(TransferOrderItemStatus::Received);
    expect($order->fresh()->status)->toBe(TransferOrderStatus::Completed);

    assertDatabaseHas('inventorix_stocks', [
        'stockable_type' => Product::class,
        'stockable_id' => $product->id,
        'location_id' => $this->toLocation->id,
        'quantity' => 15,
    ]);
});

it('receive job fails all items when order has no send transaction', function () {
    $product = Product::factory()->create();
    $product->addStock(50, $this->fromLocation->id);

    $order = TransferOrder::factory()->create([
        'from_location_id' => $this->fromLocation->id,
        'to_location_id' => $this->toLocation->id,
        'status' => TransferOrderStatus::Receiving,
    ]);

    // Item manually set to Sent but no send was ever dispatched for the order
    $item = TransferOrderItem::factory()->create([
        'transfer_order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 5,
        'item_status' => TransferOrderItemStatus::Sent,
    ]);

    ReceiveTransferItemsJob::dispatchSync($order->fresh());

    expect($item->fresh()->item_status)->toBe(TransferOrderItemStatus::Failed);
    expect($order->fresh()->status)->toBe(TransferOrderStatus::PartiallyCompleted);
});

// --- Retry Receive ---

it('retry receive re-queues failed items', function () {
    Queue::fake();

    $order = TransferOrder::factory()->create([
        'from_location_id' => $this->fromLocation->id,
        'to_location_id' => $this->toLocation->id,
        'status' => TransferOrderStatus::PartiallyCompleted,
    ]);

    $receivedItem = TransferOrderItem::factory()->received()->create(['transfer_order_id' => $order->id]);
    $failedItem = TransferOrderItem::factory()->failed()->create(['transfer_order_id' => $order->id]);

    Livewire::test(ViewTransferOrder::class, ['record' => $order->id])
        ->callAction('retry_receive');

    expect($order->fresh()->status)->toBe(TransferOrderStatus::Receiving);
    expect($failedItem->fresh()->item_status)->toBe(TransferOrderItemStatus::Sent);
    expect($failedItem->fresh()->failure_reason)->toBeNull();
    expect($receivedItem->fresh()->item_status)->toBe(TransferOrderItemStatus::Received);
    Queue::assertPushed(ReceiveTransferItemsJob::class);
});

// --- syncStatusFromItems ---

it('syncStatusFromItems send phase sets InTransit when all items sent', function () {
    $order = TransferOrder::factory()->sending()->create([
        'from_location_id' => $this->fromLocation->id,
        'to_location_id' => $this->toLocation->id,
    ]);
    TransferOrderItem::factory()->count(2)->sent()->create(['transfer_order_id' => $order->id]);

    $order->syncStatusFromItems('send');

    expect($order->fresh()->status)->toBe(TransferOrderStatus::InTransit);
});

it('syncStatusFromItems send phase sets PartiallySent when any item failed', function () {
    $order = TransferOrder::factory()->sending()->create([
        'from_location_id' => $this->fromLocation->id,
        'to_location_id' => $this->toLocation->id,
    ]);
    TransferOrderItem::factory()->sent()->create(['transfer_order_id' => $order->id]);
    TransferOrderItem::factory()->failed()->create(['transfer_order_id' => $order->id]);

    $order->syncStatusFromItems('send');

    expect($order->fresh()->status)->toBe(TransferOrderStatus::PartiallySent);
});

it('syncStatusFromItems receive phase sets Completed when all items received', function () {
    $order = TransferOrder::factory()->create([
        'from_location_id' => $this->fromLocation->id,
        'to_location_id' => $this->toLocation->id,
        'status' => TransferOrderStatus::Receiving,
    ]);
    TransferOrderItem::factory()->count(2)->received()->create(['transfer_order_id' => $order->id]);

    $order->syncStatusFromItems('receive');

    expect($order->fresh()->status)->toBe(TransferOrderStatus::Completed);
});

it('syncStatusFromItems receive phase sets PartiallyCompleted when any item failed', function () {
    $order = TransferOrder::factory()->create([
        'from_location_id' => $this->fromLocation->id,
        'to_location_id' => $this->toLocation->id,
        'status' => TransferOrderStatus::Receiving,
    ]);
    TransferOrderItem::factory()->received()->create(['transfer_order_id' => $order->id]);
    TransferOrderItem::factory()->failed()->create(['transfer_order_id' => $order->id]);

    $order->syncStatusFromItems('receive');

    expect($order->fresh()->status)->toBe(TransferOrderStatus::PartiallyCompleted);
});

// --- Cancel action ---

it('can cancel a draft transfer order', function () {
    $order = TransferOrder::factory()->create([
        'from_location_id' => $this->fromLocation->id,
        'to_location_id' => $this->toLocation->id,
    ]);

    Livewire::test(ViewTransferOrder::class, ['record' => $order->id])
        ->callAction('cancel');

    expect($order->fresh()->status)->toBe(TransferOrderStatus::Cancelled);
});

it('can cancel a confirmed transfer order', function () {
    $order = TransferOrder::factory()->confirmed()->create([
        'from_location_id' => $this->fromLocation->id,
        'to_location_id' => $this->toLocation->id,
    ]);

    Livewire::test(ViewTransferOrder::class, ['record' => $order->id])
        ->callAction('cancel');

    expect($order->fresh()->status)->toBe(TransferOrderStatus::Cancelled);
});

it('cancel is hidden after sending starts', function () {
    $order = TransferOrder::factory()->sending()->create([
        'from_location_id' => $this->fromLocation->id,
        'to_location_id' => $this->toLocation->id,
    ]);

    Livewire::test(ViewTransferOrder::class, ['record' => $order->id])
        ->assertActionHidden('cancel');
});
