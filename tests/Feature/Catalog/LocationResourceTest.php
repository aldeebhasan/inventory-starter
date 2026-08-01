<?php

use App\Enums\LocationType;
use App\Filament\Resources\Locations\Pages\CreateLocation;
use App\Filament\Resources\Locations\Pages\EditLocation;
use App\Filament\Resources\Locations\Pages\ListLocations;
use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\assertDatabaseHas;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('can render the locations list page', function () {
    Livewire::test(ListLocations::class)->assertSuccessful();
});

it('can list locations in the table', function () {
    $locations = collect([
        createLocation(['name' => 'Loc A']),
        createLocation(['name' => 'Loc B']),
    ]);

    Livewire::test(ListLocations::class)
        ->assertCanSeeTableRecords($locations);
});

it('can create a location', function () {
    Livewire::test(CreateLocation::class)
        ->fillForm([
            'name' => 'Main Warehouse',
            'code' => 'WH-MAIN',
            'meta.type' => LocationType::Warehouse->value,
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    assertDatabaseHas('inventorix_locations', [
        'name' => 'Main Warehouse',
        'code' => 'WH-MAIN',
    ]);
});

it('can create a child location with a parent', function () {
    $parent = createLocation(['name' => 'Parent Warehouse']);

    Livewire::test(CreateLocation::class)
        ->fillForm([
            'name' => 'Shelf A',
            'meta.type' => LocationType::Store->value,
            'parent_id' => $parent->id,
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    assertDatabaseHas('inventorix_locations', [
        'name' => 'Shelf A',
        'parent_id' => $parent->id,
    ]);
});

it('can edit a location', function () {
    $location = createLocation(['name' => 'Old Name']);

    Livewire::test(EditLocation::class, ['record' => $location->id])
        ->fillForm([
            'name' => 'New Name',
            'meta.type' => LocationType::Warehouse->value,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    assertDatabaseHas('inventorix_locations', [
        'id' => $location->id,
        'name' => 'New Name',
    ]);
});

it('validates name is required on create', function () {
    Livewire::test(CreateLocation::class)
        ->fillForm(['name' => ''])
        ->call('create')
        ->assertHasFormErrors(['name' => 'required']);
});

it('validates location type is required', function () {
    Livewire::test(CreateLocation::class)
        ->fillForm(['name' => 'Test Location', 'meta.type' => null])
        ->call('create')
        ->assertHasFormErrors(['meta.type' => 'required']);
});
