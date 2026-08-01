<?php

use App\Filament\Resources\Suppliers\Pages\CreateSupplier;
use App\Filament\Resources\Suppliers\Pages\EditSupplier;
use App\Filament\Resources\Suppliers\Pages\ListSuppliers;
use App\Models\Supplier;
use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\assertDatabaseHas;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('can render the suppliers list page', function () {
    Livewire::test(ListSuppliers::class)->assertSuccessful();
});

it('can list suppliers in the table', function () {
    $suppliers = Supplier::factory()->count(3)->create();

    Livewire::test(ListSuppliers::class)
        ->assertCanSeeTableRecords($suppliers);
});

it('can create a supplier', function () {
    Livewire::test(CreateSupplier::class)
        ->fillForm([
            'name' => 'Acme Corp',
            'email' => 'contact@acme.com',
            'phone' => '+1234567890',
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    assertDatabaseHas(Supplier::class, [
        'name' => 'Acme Corp',
        'email' => 'contact@acme.com',
    ]);
});

it('can edit a supplier', function () {
    $supplier = Supplier::factory()->create();

    Livewire::test(EditSupplier::class, ['record' => $supplier->id])
        ->fillForm(['name' => 'Updated Supplier', 'email' => 'new@supplier.com'])
        ->call('save')
        ->assertHasNoFormErrors();

    assertDatabaseHas(Supplier::class, [
        'id' => $supplier->id,
        'name' => 'Updated Supplier',
        'email' => 'new@supplier.com',
    ]);
});

it('validates name and email are required', function () {
    Livewire::test(CreateSupplier::class)
        ->fillForm(['name' => '', 'email' => ''])
        ->call('create')
        ->assertHasFormErrors(['name' => 'required', 'email' => 'required']);
});

it('validates email format', function () {
    Livewire::test(CreateSupplier::class)
        ->fillForm(['name' => 'Test', 'email' => 'not-an-email'])
        ->call('create')
        ->assertHasFormErrors(['email' => 'email']);
});

it('can filter suppliers by active status', function () {
    $active = Supplier::factory()->create(['is_active' => true]);
    $inactive = Supplier::factory()->inactive()->create();

    Livewire::test(ListSuppliers::class)
        ->filterTable('is_active', true)
        ->assertCanSeeTableRecords([$active])
        ->assertCanNotSeeTableRecords([$inactive]);
});

it('can search suppliers by name', function () {
    $supplier = Supplier::factory()->create(['name' => 'SearchableSupplier']);
    $other = Supplier::factory()->create(['name' => 'OtherCompany']);

    Livewire::test(ListSuppliers::class)
        ->searchTable('SearchableSupplier')
        ->assertCanSeeTableRecords([$supplier])
        ->assertCanNotSeeTableRecords([$other]);
});
