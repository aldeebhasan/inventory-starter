<?php

use Aldeebhasan\Inventorix\Models\Stock;
use App\Enums\ProductType;
use App\Filament\Pages\InventoryValuation;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->location = createLocation();
    $this->product = Product::factory()->create([
        'type' => ProductType::Inventory,
        'cost' => 25.00,
    ]);
});

it('can render the inventory valuation page', function () {
    Livewire::test(InventoryValuation::class)->assertSuccessful();
});

it('lists stock records with positive quantity', function () {
    $this->product->addStock(10, $this->location);

    $stocks = Stock::whereHasMorph('stockable', [Product::class])
        ->where('quantity', '>', 0)
        ->get();

    Livewire::test(InventoryValuation::class)
        ->assertCanSeeTableRecords($stocks);
});

it('does not show zero-quantity stocks', function () {
    $this->product->addStock(5, $this->location);
    $this->product->deductStock(5, $this->location);

    $stocks = Stock::whereHasMorph('stockable', [Product::class])
        ->where('stockable_id', $this->product->id)
        ->get();

    Livewire::test(InventoryValuation::class)
        ->assertCanNotSeeTableRecords($stocks);
});

it('can filter by location', function () {
    $otherLocation = createLocation(['name' => 'Other Warehouse']);

    $this->product->addStock(10, $this->location);

    $product2 = Product::factory()->create(['type' => ProductType::Inventory, 'cost' => 15.00]);
    $product2->addStock(5, $otherLocation);

    $stockInLocation = Stock::where('location_id', $this->location->id)->get();
    $stockInOther = Stock::where('location_id', $otherLocation->id)->get();

    Livewire::test(InventoryValuation::class)
        ->filterTable('location_id', $this->location->id)
        ->assertCanSeeTableRecords($stockInLocation)
        ->assertCanNotSeeTableRecords($stockInOther);
});

it('can filter by brand', function () {
    $brand = Brand::create(['name' => 'TestBrand']);
    $this->product->update(['brand_id' => $brand->id]);
    $this->product->addStock(10, $this->location);

    $product2 = Product::factory()->create(['type' => ProductType::Inventory, 'cost' => 15.00]);
    $product2->addStock(5, $this->location);

    $stock1 = Stock::where('stockable_id', $this->product->id)->get();
    $stock2 = Stock::where('stockable_id', $product2->id)->get();

    Livewire::test(InventoryValuation::class)
        ->filterTable('brand', $brand->id)
        ->assertCanSeeTableRecords($stock1)
        ->assertCanNotSeeTableRecords($stock2);
});

it('can filter by category', function () {
    $category = Category::create(['name' => 'Valuation Category']);
    $this->product->categories()->attach($category);
    $this->product->addStock(10, $this->location);

    $product2 = Product::factory()->create(['type' => ProductType::Inventory, 'cost' => 15.00]);
    $product2->addStock(5, $this->location);

    $stock1 = Stock::where('stockable_id', $this->product->id)->get();
    $stock2 = Stock::where('stockable_id', $product2->id)->get();

    Livewire::test(InventoryValuation::class)
        ->filterTable('category', $category->id)
        ->assertCanSeeTableRecords($stock1)
        ->assertCanNotSeeTableRecords($stock2);
});
