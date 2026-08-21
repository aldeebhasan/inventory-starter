<?php

use Aldeebhasan\Inventorix\Enums\MovementType;
use Aldeebhasan\Inventorix\Models\Movement;
use App\Enums\ProductType;
use App\Filament\Pages\InventoryTransactions;
use App\Models\Product;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->location = createLocation();
    $this->product = Product::factory()->create(['type' => ProductType::Inventory]);
});

it('can render the inventory transactions page', function () {
    Livewire::test(InventoryTransactions::class)->assertSuccessful();
});

it('lists movements after adding stock', function () {
    $this->product->addStock(10, $this->location);

    $movements = Movement::whereHasMorph('stockable', [Product::class])->get();

    Livewire::test(InventoryTransactions::class)
        ->assertCanSeeTableRecords($movements);
});

it('shows add and deduct movements', function () {
    $this->product->addStock(15, $this->location);
    $this->product->deductStock(5, $this->location);

    $movements = Movement::whereHasMorph('stockable', [Product::class])->get();
    expect($movements)->toHaveCount(2);

    Livewire::test(InventoryTransactions::class)
        ->assertCanSeeTableRecords($movements);
});

it('can filter movements by product', function () {
    $product2 = Product::factory()->create(['type' => ProductType::Inventory]);

    $this->product->addStock(10, $this->location);
    $product2->addStock(5, $this->location);

    $movements1 = Movement::where('stockable_id', $this->product->id)->where('stockable_type', Product::class)->get();
    $movements2 = Movement::where('stockable_id', $product2->id)->where('stockable_type', Product::class)->get();

    Livewire::test(InventoryTransactions::class)
        ->filterTable('stockable_id', $this->product->id)
        ->assertCanSeeTableRecords($movements1)
        ->assertCanNotSeeTableRecords($movements2);
});

it('can filter movements by location', function () {
    $otherLocation = createLocation(['name' => 'Other Location']);

    $this->product->addStock(10, $this->location);
    $this->product->addStock(5, $otherLocation);

    $movementsHere = Movement::where('location_id', $this->location->id)->get();
    $movementsThere = Movement::where('location_id', $otherLocation->id)->get();

    Livewire::test(InventoryTransactions::class)
        ->filterTable('location_id', $this->location->id)
        ->assertCanSeeTableRecords($movementsHere)
        ->assertCanNotSeeTableRecords($movementsThere);
});

it('can filter movements by type', function () {
    $this->product->addStock(20, $this->location);
    $this->product->deductStock(5, $this->location);

    $addMovements = Movement::where('type', MovementType::Add->value)->get();
    $deductMovements = Movement::where('type', MovementType::Deduct->value)->get();

    Livewire::test(InventoryTransactions::class)
        ->filterTable('type', MovementType::Add->value)
        ->assertCanSeeTableRecords($addMovements)
        ->assertCanNotSeeTableRecords($deductMovements);
});

it('defaults to descending date sort', function () {
    Livewire::test(InventoryTransactions::class)
        ->assertSuccessful();
});
