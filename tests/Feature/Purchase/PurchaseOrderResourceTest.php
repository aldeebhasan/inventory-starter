<?php

use App\Enums\PurchaseOrderStatus;
use App\Filament\Resources\PurchaseOrders\Pages\CreatePurchaseOrder;
use App\Filament\Resources\PurchaseOrders\Pages\ListPurchaseOrders;
use App\Filament\Resources\PurchaseOrders\Pages\ViewPurchaseOrder;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertSoftDeleted;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->location = createLocation();
    $this->supplier = Supplier::factory()->create();
});

// --- List & Create ---

it('can render the purchase orders list page', function () {
    Livewire::test(ListPurchaseOrders::class)->assertSuccessful();
});

it('can list purchase orders in the table', function () {
    $orders = PurchaseOrder::factory()->count(3)->create([
        'supplier_id' => $this->supplier->id,
        'location_id' => $this->location->id,
    ]);

    Livewire::test(ListPurchaseOrders::class)
        ->assertCanSeeTableRecords($orders);
});

it('auto-generates order number on creation', function () {
    $order = PurchaseOrder::factory()->create([
        'supplier_id' => $this->supplier->id,
        'location_id' => $this->location->id,
    ]);

    expect($order->order_number)->toStartWith('PO-');
});

it('can create a purchase order', function () {
    Livewire::test(CreatePurchaseOrder::class)
        ->fillForm([
            'supplier_id' => $this->supplier->id,
            'location_id' => $this->location->id,
            'ordered_at' => now()->toDateTimeString(),
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    assertDatabaseHas(PurchaseOrder::class, [
        'supplier_id' => $this->supplier->id,
        'location_id' => $this->location->id,
        'status' => PurchaseOrderStatus::Draft,
    ]);
});

it('can soft-delete a purchase order', function () {
    $order = PurchaseOrder::factory()->create([
        'supplier_id' => $this->supplier->id,
        'location_id' => $this->location->id,
    ]);

    Livewire::test(ListPurchaseOrders::class)
        ->callAction(TestAction::make('delete')->table($order));

    assertSoftDeleted(PurchaseOrder::class, ['id' => $order->id]);
});

// --- Workflow Actions ---

it('can confirm a draft purchase order', function () {
    $order = PurchaseOrder::factory()->create([
        'supplier_id' => $this->supplier->id,
        'location_id' => $this->location->id,
        'status' => PurchaseOrderStatus::Draft,
    ]);

    Livewire::test(ViewPurchaseOrder::class, ['record' => $order->id])
        ->callAction('confirm');

    expect($order->fresh()->status)->toBe(PurchaseOrderStatus::Confirmed);
});

it('can receive a confirmed purchase order and adds stock', function () {
    $product = Product::factory()->create();
    $order = PurchaseOrder::factory()->create([
        'supplier_id' => $this->supplier->id,
        'location_id' => $this->location->id,
        'status' => PurchaseOrderStatus::Confirmed,
    ]);
    PurchaseOrderItem::factory()->create([
        'purchase_order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 10,
        'unit_cost' => 5.00,
    ]);

    Livewire::test(ViewPurchaseOrder::class, ['record' => $order->id])
        ->callAction('receive');

    $order->refresh();
    expect($order->status)->toBe(PurchaseOrderStatus::Received);
    expect($order->received_at)->not->toBeNull();

    // Verify stock was added to inventorix
    assertDatabaseHas('inventorix_stocks', [
        'stockable_type' => Product::class,
        'stockable_id' => $product->id,
        'location_id' => $this->location->id,
    ]);
});

it('can cancel a draft purchase order', function () {
    $order = PurchaseOrder::factory()->create([
        'supplier_id' => $this->supplier->id,
        'location_id' => $this->location->id,
        'status' => PurchaseOrderStatus::Draft,
    ]);

    Livewire::test(ViewPurchaseOrder::class, ['record' => $order->id])
        ->callAction('cancel');

    expect($order->fresh()->status)->toBe(PurchaseOrderStatus::Cancelled);
});

it('can cancel a confirmed purchase order', function () {
    $order = PurchaseOrder::factory()->confirmed()->create([
        'supplier_id' => $this->supplier->id,
        'location_id' => $this->location->id,
    ]);

    Livewire::test(ViewPurchaseOrder::class, ['record' => $order->id])
        ->callAction('cancel');

    expect($order->fresh()->status)->toBe(PurchaseOrderStatus::Cancelled);
});

it('confirm action is not visible on a received order', function () {
    $order = PurchaseOrder::factory()->received()->create([
        'supplier_id' => $this->supplier->id,
        'location_id' => $this->location->id,
    ]);

    Livewire::test(ViewPurchaseOrder::class, ['record' => $order->id])
        ->assertActionHidden('confirm');
});

it('cancel action is not visible on a received order', function () {
    $order = PurchaseOrder::factory()->received()->create([
        'supplier_id' => $this->supplier->id,
        'location_id' => $this->location->id,
    ]);

    Livewire::test(ViewPurchaseOrder::class, ['record' => $order->id])
        ->assertActionHidden('cancel');
});

it('cannot edit a confirmed purchase order', function () {
    $order = PurchaseOrder::factory()->confirmed()->create([
        'supplier_id' => $this->supplier->id,
        'location_id' => $this->location->id,
    ]);

    $this->get(route('filament.admin.resources.purchase-orders.edit', $order))
        ->assertForbidden();
});

it('can filter purchase orders by status', function () {
    $draft = PurchaseOrder::factory()->create([
        'supplier_id' => $this->supplier->id,
        'location_id' => $this->location->id,
        'status' => PurchaseOrderStatus::Draft,
    ]);
    $confirmed = PurchaseOrder::factory()->confirmed()->create([
        'supplier_id' => $this->supplier->id,
        'location_id' => $this->location->id,
    ]);

    Livewire::test(ListPurchaseOrders::class)
        ->filterTable('status', PurchaseOrderStatus::Draft->value)
        ->assertCanSeeTableRecords([$draft])
        ->assertCanNotSeeTableRecords([$confirmed]);
});
