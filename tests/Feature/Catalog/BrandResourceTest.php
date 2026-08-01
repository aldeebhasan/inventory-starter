<?php

use App\Filament\Resources\Brands\Pages\CreateBrand;
use App\Filament\Resources\Brands\Pages\EditBrand;
use App\Filament\Resources\Brands\Pages\ListBrands;
use App\Models\Brand;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertSoftDeleted;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('can render the brands list page', function () {
    Livewire::test(ListBrands::class)->assertSuccessful();
});

it('can list brands in the table', function () {
    $brands = Brand::factory()->count(3)->create();

    Livewire::test(ListBrands::class)
        ->assertCanSeeTableRecords($brands);
});

it('can create a brand', function () {
    Livewire::test(CreateBrand::class)
        ->fillForm([
            'name' => 'Nike',
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    assertDatabaseHas(Brand::class, ['name' => 'Nike', 'is_active' => true]);
});

it('can edit a brand', function () {
    $brand = Brand::factory()->create();

    Livewire::test(EditBrand::class, ['record' => $brand->id])
        ->fillForm(['name' => 'Updated Brand', 'is_active' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    assertDatabaseHas(Brand::class, ['id' => $brand->id, 'name' => 'Updated Brand', 'is_active' => false]);
});

it('validates name is required on create', function () {
    Livewire::test(CreateBrand::class)
        ->fillForm(['name' => ''])
        ->call('create')
        ->assertHasFormErrors(['name' => 'required']);
});

it('can soft-delete a brand', function () {
    $brand = Brand::factory()->create();

    Livewire::test(ListBrands::class)
        ->callAction(TestAction::make('delete')->table($brand));

    assertSoftDeleted(Brand::class, ['id' => $brand->id]);
});

it('can restore a soft-deleted brand', function () {
    $brand = Brand::factory()->create();
    $brand->delete();

    Livewire::test(ListBrands::class)
        ->callAction(TestAction::make('restore')->table($brand));

    assertDatabaseHas(Brand::class, ['id' => $brand->id, 'deleted_at' => null]);
});

it('can filter brands by active status', function () {
    $active = Brand::factory()->create(['is_active' => true]);
    $inactive = Brand::factory()->inactive()->create();

    Livewire::test(ListBrands::class)
        ->filterTable('is_active', true)
        ->assertCanSeeTableRecords([$active])
        ->assertCanNotSeeTableRecords([$inactive]);
});
