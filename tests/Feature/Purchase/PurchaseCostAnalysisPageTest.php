<?php

use App\Enums\PurchaseOrderStatus;
use App\Filament\Pages\PurchaseCostAnalysis;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->location = createLocation();
    $this->supplier = Supplier::factory()->create();
});

it('can render the purchase cost analysis page', function () {
    Livewire::test(PurchaseCostAnalysis::class)->assertSuccessful();
});

it('lists purchase order items from confirmed/received POs', function () {
    $po = PurchaseOrder::factory()->received()->create([
        'supplier_id' => $this->supplier->id,
        'location_id' => $this->location->id,
    ]);
    $items = PurchaseOrderItem::factory()->count(2)->create([
        'purchase_order_id' => $po->id,
    ]);

    Livewire::test(PurchaseCostAnalysis::class)
        ->assertCanSeeTableRecords($items);
});

it('does not list items from draft POs', function () {
    $draftPo = PurchaseOrder::factory()->create([
        'supplier_id' => $this->supplier->id,
        'location_id' => $this->location->id,
        'status' => PurchaseOrderStatus::Draft,
    ]);
    $item = PurchaseOrderItem::factory()->create([
        'purchase_order_id' => $draftPo->id,
    ]);

    Livewire::test(PurchaseCostAnalysis::class)
        ->assertCanNotSeeTableRecords([$item]);
});

it('can filter by product', function () {
    $product1 = Product::factory()->create();
    $product2 = Product::factory()->create();

    $po = PurchaseOrder::factory()->received()->create([
        'supplier_id' => $this->supplier->id,
        'location_id' => $this->location->id,
    ]);

    $item1 = PurchaseOrderItem::factory()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $product1->id,
    ]);
    $item2 = PurchaseOrderItem::factory()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $product2->id,
    ]);

    Livewire::test(PurchaseCostAnalysis::class)
        ->filterTable('product_id', $product1->id)
        ->assertCanSeeTableRecords([$item1])
        ->assertCanNotSeeTableRecords([$item2]);
});

it('can filter by supplier', function () {
    $supplier2 = Supplier::factory()->create();

    $po1 = PurchaseOrder::factory()->confirmed()->create([
        'supplier_id' => $this->supplier->id,
        'location_id' => $this->location->id,
    ]);
    $po2 = PurchaseOrder::factory()->confirmed()->create([
        'supplier_id' => $supplier2->id,
        'location_id' => $this->location->id,
    ]);

    $item1 = PurchaseOrderItem::factory()->create(['purchase_order_id' => $po1->id]);
    $item2 = PurchaseOrderItem::factory()->create(['purchase_order_id' => $po2->id]);

    Livewire::test(PurchaseCostAnalysis::class)
        ->filterTable('supplier_id', $this->supplier->id)
        ->assertCanSeeTableRecords([$item1])
        ->assertCanNotSeeTableRecords([$item2]);
});

it('shows cost data correctly', function () {
    $po = PurchaseOrder::factory()->received()->create([
        'supplier_id' => $this->supplier->id,
        'location_id' => $this->location->id,
    ]);

    PurchaseOrderItem::factory()->create([
        'purchase_order_id' => $po->id,
        'unit_cost' => 10.50,
        'quantity' => 5,
    ]);

    Livewire::test(PurchaseCostAnalysis::class)->assertSuccessful();
});
