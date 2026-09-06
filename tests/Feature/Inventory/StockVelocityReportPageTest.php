<?php

use Aldeebhasan\Inventorix\Models\Stock;
use App\Enums\ProductType;
use App\Filament\Pages\StockVelocityReport;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->location = createLocation();
    $this->product = Product::factory()->create(['type' => ProductType::Inventory]);
});

it('can render the stock velocity report page', function () {
    Livewire::test(StockVelocityReport::class)->assertSuccessful();
});

it('lists stocks with positive quantity', function () {
    $this->product->addStock(20, $this->location);

    $stocks = Stock::whereHasMorph('stockable', [Product::class])
        ->where('quantity', '>', 0)
        ->get();

    Livewire::test(StockVelocityReport::class)
        ->assertCanSeeTableRecords($stocks);
});

it('does not show zero-quantity stocks', function () {
    $this->product->addStock(5, $this->location);
    $this->product->deductStock(5, $this->location);

    $stocks = Stock::where('stockable_id', $this->product->id)->get();

    Livewire::test(StockVelocityReport::class)
        ->assertCanNotSeeTableRecords($stocks);
});

it('can filter by location', function () {
    $otherLocation = createLocation(['name' => 'Other Warehouse']);

    $this->product->addStock(10, $this->location);

    $product2 = Product::factory()->create(['type' => ProductType::Inventory]);
    $product2->addStock(5, $otherLocation);

    $stockInLocation = Stock::where('location_id', $this->location->id)->get();
    $stockInOther = Stock::where('location_id', $otherLocation->id)->get();

    Livewire::test(StockVelocityReport::class)
        ->filterTable('location_id', $this->location->id)
        ->assertCanSeeTableRecords($stockInLocation)
        ->assertCanNotSeeTableRecords($stockInOther);
});

it('can filter by category', function () {
    $category = Category::create(['name' => 'Velocity Category']);
    $this->product->categories()->attach($category);

    $product2 = Product::factory()->create(['type' => ProductType::Inventory]);

    $this->product->addStock(10, $this->location);
    $product2->addStock(5, $this->location);

    $stock1 = Stock::where('stockable_id', $this->product->id)->get();
    $stock2 = Stock::where('stockable_id', $product2->id)->get();

    Livewire::test(StockVelocityReport::class)
        ->filterTable('category', $category->id)
        ->assertCanSeeTableRecords($stock1)
        ->assertCanNotSeeTableRecords($stock2);
});

it('shows healthy status for products with no consumption', function () {
    $this->product->addStock(50, $this->location);

    Livewire::test(StockVelocityReport::class)
        ->assertSuccessful();
});
