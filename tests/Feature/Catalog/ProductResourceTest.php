<?php

use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertSoftDeleted;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('can render the products list page', function () {
    Livewire::test(ListProducts::class)->assertSuccessful();
});

it('can list products in the table', function () {
    $products = Product::factory()->count(3)->create();

    Livewire::test(ListProducts::class)
        ->assertCanSeeTableRecords($products);
});

it('can create a product', function () {
    Livewire::test(CreateProduct::class)
        ->fillForm([
            'name' => 'Test Product',
            'price' => 99.99,
            'cost' => 50.00,
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    assertDatabaseHas(Product::class, ['name' => 'Test Product']);
});

it('can create a product with brand and unit', function () {
    $brand = Brand::factory()->create();
    $unit = Unit::factory()->create();

    Livewire::test(CreateProduct::class)
        ->fillForm([
            'name' => 'Branded Product',
            'price' => 50.00,
            'cost' => 25.00,
            'brand_id' => $brand->id,
            'unit_id' => $unit->id,
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    assertDatabaseHas(Product::class, [
        'name' => 'Branded Product',
        'brand_id' => $brand->id,
        'unit_id' => $unit->id,
    ]);
});

it('can create a product with categories', function () {
    $category = Category::create(['name' => 'Electronics']);

    Livewire::test(CreateProduct::class)
        ->fillForm([
            'name' => 'Electronics Product',
            'price' => 100.00,
            'cost' => 60.00,
            'categories' => [$category->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    $product = Product::where('name', 'Electronics Product')->first();
    expect($product->categories()->where('categories.id', $category->id)->exists())->toBeTrue();
});

it('can edit a product', function () {
    $product = Product::factory()->create();

    Livewire::test(EditProduct::class, ['record' => $product->id])
        ->fillForm(['name' => 'Updated Product', 'price' => 199.99])
        ->call('save')
        ->assertHasNoFormErrors();

    assertDatabaseHas(Product::class, [
        'id' => $product->id,
        'name' => 'Updated Product',
    ]);
});

it('validates name and price are required', function () {
    Livewire::test(CreateProduct::class)
        ->fillForm(['name' => '', 'price' => null, 'cost' => null])
        ->call('create')
        ->assertHasFormErrors(['name' => 'required']);
});

it('can soft-delete a product from the list', function () {
    $product = Product::factory()->create();

    Livewire::test(ListProducts::class)
        ->callAction(TestAction::make('delete')->table($product));

    assertSoftDeleted(Product::class, ['id' => $product->id]);
});

it('can search products by name', function () {
    $product = Product::factory()->create(['name' => 'UniqueProductName']);
    $other = Product::factory()->create(['name' => 'OtherItem']);

    Livewire::test(ListProducts::class)
        ->searchTable('UniqueProductName')
        ->assertCanSeeTableRecords([$product])
        ->assertCanNotSeeTableRecords([$other]);
});
