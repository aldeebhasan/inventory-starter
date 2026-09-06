<?php

use App\Enums\ProductType;
use App\Filament\Pages\ProductMovementSummary;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->location = createLocation();
    $this->product = Product::factory()->create(['type' => ProductType::Inventory]);
});

it('can render the product movement summary page', function () {
    Livewire::test(ProductMovementSummary::class)->assertSuccessful();
});

it('lists products in the table', function () {
    $this->product->addStock(10, $this->location);

    Livewire::test(ProductMovementSummary::class)
        ->assertCanSeeTableRecords([$this->product]);
});

it('can filter by category', function () {
    $category = Category::create(['name' => 'Movement Category']);
    $this->product->categories()->attach($category);

    $product2 = Product::factory()->create(['type' => ProductType::Inventory]);

    $this->product->addStock(10, $this->location);
    $product2->addStock(5, $this->location);

    Livewire::test(ProductMovementSummary::class)
        ->filterTable('category', $category->id)
        ->assertCanSeeTableRecords([$this->product])
        ->assertCanNotSeeTableRecords([$product2]);
});

it('shows movements for products with stock changes', function () {
    $this->product->addStock(20, $this->location);
    $this->product->deductStock(5, $this->location);

    Livewire::test(ProductMovementSummary::class)
        ->assertCanSeeTableRecords([$this->product]);
});
