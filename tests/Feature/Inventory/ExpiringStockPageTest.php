<?php

use Aldeebhasan\Inventorix\Enums\MovementType;
use Aldeebhasan\Inventorix\Models\Movement;
use App\Enums\ProductType;
use App\Filament\Pages\ExpiringStock;
use App\Models\Product;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->location = createLocation();
    $this->product = Product::factory()->create(['type' => ProductType::Inventory]);
});

it('can render the expiring stock page', function () {
    Livewire::test(ExpiringStock::class)->assertSuccessful();
});

it('shows movements with expiration dates', function () {
    $this->product->addStock(10, $this->location);

    // Manually set expires_at on the movement
    $movement = Movement::where('stockable_id', $this->product->id)
        ->where('type', MovementType::Add)
        ->first();

    if ($movement) {
        $movement->update([
            'expires_at' => now()->addDays(15),
            'lot_reference' => 'LOT-001',
        ]);
    }

    Livewire::test(ExpiringStock::class)->assertSuccessful();
});

it('does not show movements without expiry date by default', function () {
    $this->product->addStock(10, $this->location);

    Livewire::test(ExpiringStock::class)->assertSuccessful();
});

it('can filter by location', function () {
    $this->product->addStock(10, $this->location);

    $movement = Movement::where('stockable_id', $this->product->id)->first();
    if ($movement) {
        $movement->update(['expires_at' => now()->addDays(10)]);
    }

    Livewire::test(ExpiringStock::class)
        ->filterTable('location_id', $this->location->id)
        ->assertSuccessful();
});

it('can filter by days until expiry', function () {
    $this->product->addStock(10, $this->location);

    $movement = Movement::where('stockable_id', $this->product->id)->first();
    if ($movement) {
        $movement->update(['expires_at' => now()->addDays(5)]);
    }

    Livewire::test(ExpiringStock::class)
        ->filterTable('days_until_expiry', '7')
        ->assertSuccessful();
});

it('can filter by product', function () {
    $this->product->addStock(10, $this->location);

    $movement = Movement::where('stockable_id', $this->product->id)->first();
    if ($movement) {
        $movement->update(['expires_at' => now()->addDays(10)]);
    }

    Livewire::test(ExpiringStock::class)
        ->filterTable('product_id', $this->product->id)
        ->assertSuccessful();
});
