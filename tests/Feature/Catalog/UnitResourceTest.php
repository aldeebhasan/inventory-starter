<?php

use App\Filament\Resources\Units\Pages\CreateUnit;
use App\Filament\Resources\Units\Pages\EditUnit;
use App\Filament\Resources\Units\Pages\ListUnits;
use App\Models\Unit;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('can render the units list page', function () {
    Livewire::test(ListUnits::class)->assertSuccessful();
});

it('can list units in the table', function () {
    $units = Unit::factory()->count(3)->create();

    Livewire::test(ListUnits::class)
        ->assertCanSeeTableRecords($units);
});

it('can create a unit', function () {
    Livewire::test(CreateUnit::class)
        ->fillForm([
            'name' => 'Kilogram',
            'abbreviation' => 'kg',
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    assertDatabaseHas(Unit::class, [
        'name' => 'Kilogram',
        'abbreviation' => 'kg',
    ]);
});

it('can create a unit with conversion factor', function () {
    $baseUnit = Unit::factory()->create(['name' => 'Gram', 'abbreviation' => 'g']);

    Livewire::test(CreateUnit::class)
        ->fillForm([
            'name' => 'Kilogram',
            'abbreviation' => 'kg',
            'base_unit_id' => $baseUnit->id,
            'conversion_factor' => 1000,
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    assertDatabaseHas(Unit::class, [
        'name' => 'Kilogram',
        'base_unit_id' => $baseUnit->id,
        'conversion_factor' => 1000,
    ]);
});

it('can edit a unit', function () {
    $unit = Unit::factory()->create();

    Livewire::test(EditUnit::class, ['record' => $unit->id])
        ->fillForm([
            'name' => 'Updated Name',
            'abbreviation' => 'un',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    assertDatabaseHas(Unit::class, [
        'id' => $unit->id,
        'name' => 'Updated Name',
        'abbreviation' => 'un',
    ]);
});

it('validates required fields on create', function () {
    Livewire::test(CreateUnit::class)
        ->fillForm(['name' => '', 'abbreviation' => ''])
        ->call('create')
        ->assertHasFormErrors(['name' => 'required', 'abbreviation' => 'required']);
});

it('can delete a unit', function () {
    $unit = Unit::factory()->create();

    Livewire::test(ListUnits::class)
        ->callAction(TestAction::make('delete')->table($unit));

    assertDatabaseMissing(Unit::class, ['id' => $unit->id]);
});

it('shows products count column', function () {
    $unit = Unit::factory()->create();

    Livewire::test(ListUnits::class)
        ->assertCanSeeTableRecords([$unit]);
});
