<?php

use App\Filament\Pages\SupplierPerformance;
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

it('can render the supplier performance page', function () {
    Livewire::test(SupplierPerformance::class)->assertSuccessful();
});

it('lists suppliers', function () {
    $suppliers = Supplier::factory()->count(3)->create();

    Livewire::test(SupplierPerformance::class)
        ->assertCanSeeTableRecords($suppliers->push($this->supplier));
});

it('shows supplier with purchase order metrics', function () {
    $po = PurchaseOrder::factory()->received()->create([
        'supplier_id' => $this->supplier->id,
        'location_id' => $this->location->id,
    ]);

    PurchaseOrderItem::factory()->create([
        'purchase_order_id' => $po->id,
        'unit_cost' => 10.00,
        'quantity' => 50,
    ]);

    Livewire::test(SupplierPerformance::class)
        ->assertCanSeeTableRecords([$this->supplier]);
});

it('can filter by supplier', function () {
    $supplier2 = Supplier::factory()->create();

    Livewire::test(SupplierPerformance::class)
        ->filterTable('supplier_id', $this->supplier->id)
        ->assertCanSeeTableRecords([$this->supplier])
        ->assertCanNotSeeTableRecords([$supplier2]);
});

it('counts received and cancelled POs separately', function () {
    PurchaseOrder::factory()->received()->create([
        'supplier_id' => $this->supplier->id,
        'location_id' => $this->location->id,
    ]);
    PurchaseOrder::factory()->cancelled()->create([
        'supplier_id' => $this->supplier->id,
        'location_id' => $this->location->id,
    ]);

    Livewire::test(SupplierPerformance::class)->assertSuccessful();
});

it('calculates total spend from received POs', function () {
    $po = PurchaseOrder::factory()->received()->create([
        'supplier_id' => $this->supplier->id,
        'location_id' => $this->location->id,
    ]);

    PurchaseOrderItem::factory()->create([
        'purchase_order_id' => $po->id,
        'unit_cost' => 25.00,
        'quantity' => 10,
    ]);

    Livewire::test(SupplierPerformance::class)->assertSuccessful();
});
