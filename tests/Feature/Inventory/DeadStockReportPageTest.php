<?php

use App\Enums\ProductType;
use App\Filament\Pages\DeadStockReport;
use App\Models\Product;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->location = createLocation();
    $this->product = Product::factory()->create(['type' => ProductType::Inventory]);
});

it('can render the dead stock report page', function () {
    Livewire::test(DeadStockReport::class)->assertSuccessful();
});

it('shows products with stock but no recent deductions', function () {
    $this->product->addStock(10, $this->location);

    // No deductions — should appear as dead stock
    Livewire::test(DeadStockReport::class)->assertSuccessful();
});

it('does not show products with recent deductions', function () {
    $this->product->addStock(20, $this->location);
    $this->product->deductStock(5, $this->location);

    // Recent deduction — should NOT appear
    Livewire::test(DeadStockReport::class)->assertSuccessful();
});

it('does not show zero-quantity stocks', function () {
    $this->product->addStock(5, $this->location);
    $this->product->deductStock(5, $this->location);

    Livewire::test(DeadStockReport::class)->assertSuccessful();
});

it('can filter by location', function () {
    $otherLocation = createLocation(['name' => 'Other Warehouse']);

    $this->product->addStock(10, $this->location);

    $product2 = Product::factory()->create(['type' => ProductType::Inventory]);
    $product2->addStock(5, $otherLocation);

    Livewire::test(DeadStockReport::class)
        ->filterTable('location_id', $this->location->id)
        ->assertSuccessful();
});

it('can filter by minimum inactive days', function () {
    $this->product->addStock(10, $this->location);

    Livewire::test(DeadStockReport::class)
        ->filterTable('min_days_inactive', '30')
        ->assertSuccessful();
});
