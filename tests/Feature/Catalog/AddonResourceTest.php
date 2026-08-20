<?php

use App\Filament\Resources\Addons\Pages\CreateAddon;
use App\Filament\Resources\Addons\Pages\EditAddon;
use App\Filament\Resources\Addons\Pages\ListAddons;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Models\Addon;
use App\Models\Product;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertSoftDeleted;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('can render the addons list page', function () {
    Livewire::test(ListAddons::class)->assertSuccessful();
});

it('can list addons in the table', function () {
    $addons = Addon::factory()->count(3)->create();

    Livewire::test(ListAddons::class)
        ->assertCanSeeTableRecords($addons);
});

it('can create an addon', function () {
    Livewire::test(CreateAddon::class)
        ->fillForm([
            'name' => 'Gift Wrap',
            'price' => 5.500,
            'description' => 'Beautiful gift wrapping',
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    assertDatabaseHas(Addon::class, ['name' => 'Gift Wrap', 'is_active' => true]);
});

it('can edit an addon', function () {
    $addon = Addon::factory()->create();

    Livewire::test(EditAddon::class, ['record' => $addon->id])
        ->fillForm(['name' => 'Updated Addon', 'price' => 9.99])
        ->call('save')
        ->assertHasNoFormErrors();

    assertDatabaseHas(Addon::class, ['id' => $addon->id, 'name' => 'Updated Addon']);
});

it('validates name and price are required on create', function () {
    Livewire::test(CreateAddon::class)
        ->fillForm(['name' => '', 'price' => null])
        ->call('create')
        ->assertHasFormErrors(['name' => 'required', 'price' => 'required']);
});

it('can soft-delete an addon', function () {
    $addon = Addon::factory()->create();

    Livewire::test(ListAddons::class)
        ->callAction(TestAction::make('delete')->table($addon));

    assertSoftDeleted(Addon::class, ['id' => $addon->id]);
});

it('can restore a soft-deleted addon', function () {
    $addon = Addon::factory()->create();
    $addon->delete();

    Livewire::test(ListAddons::class)
        ->callAction(TestAction::make('restore')->table($addon));

    assertDatabaseHas(Addon::class, ['id' => $addon->id, 'deleted_at' => null]);
});

it('can filter addons by active status', function () {
    $active = Addon::factory()->create(['is_active' => true]);
    $inactive = Addon::factory()->inactive()->create();

    Livewire::test(ListAddons::class)
        ->filterTable('is_active', true)
        ->assertCanSeeTableRecords([$active])
        ->assertCanNotSeeTableRecords([$inactive]);
});

it('can search addons by name', function () {
    $addon = Addon::factory()->create(['name' => 'UniqueAddonName']);
    $other = Addon::factory()->create(['name' => 'OtherAddon']);

    Livewire::test(ListAddons::class)
        ->searchTable('UniqueAddonName')
        ->assertCanSeeTableRecords([$addon])
        ->assertCanNotSeeTableRecords([$other]);
});

it('can attach addons to a product via the product form', function () {
    $addon = Addon::factory()->create();

    Livewire::test(CreateProduct::class)
        ->fillForm([
            'name' => 'Product With Addon',
            'price' => 99.99,
            'cost' => 50.00,
            'addons' => [$addon->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    $product = Product::where('name', 'Product With Addon')->first();
    expect($product->addons()->where('addons.id', $addon->id)->exists())->toBeTrue();
});
