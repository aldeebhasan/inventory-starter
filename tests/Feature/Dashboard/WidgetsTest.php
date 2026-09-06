<?php

use App\Enums\ProductType;
use App\Filament\Widgets\InventoryStatsWidget;
use App\Filament\Widgets\RecentMovementsWidget;
use App\Models\Product;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->location = createLocation();
});

it('can render the inventory stats widget', function () {
    Livewire::test(InventoryStatsWidget::class)->assertSuccessful();
});

it('can render the recent movements widget', function () {
    Livewire::test(RecentMovementsWidget::class)->assertSuccessful();
});

it('shows correct total products count', function () {
    Product::factory()->count(3)->create(['type' => ProductType::Inventory]);

    $component = Livewire::test(InventoryStatsWidget::class);
    $component->assertSuccessful();
});

it('shows recent movements in widget', function () {
    $product = Product::factory()->create(['type' => ProductType::Inventory]);
    $product->addStock(10, $this->location);

    Livewire::test(RecentMovementsWidget::class)
        ->assertSuccessful();
});
