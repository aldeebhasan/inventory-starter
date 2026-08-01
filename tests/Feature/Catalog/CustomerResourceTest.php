<?php

use App\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Models\Customer;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertSoftDeleted;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('can render the customers list page', function () {
    Livewire::test(ListCustomers::class)->assertSuccessful();
});

it('can list customers in the table', function () {
    $customers = Customer::factory()->count(3)->create();

    Livewire::test(ListCustomers::class)
        ->assertCanSeeTableRecords($customers);
});

it('can create a customer', function () {
    Livewire::test(CreateCustomer::class)
        ->fillForm([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'phone' => '+9876543210',
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    assertDatabaseHas(Customer::class, [
        'name' => 'John Doe',
        'email' => 'john@example.com',
    ]);
});

it('can edit a customer', function () {
    $customer = Customer::factory()->create();

    Livewire::test(EditCustomer::class, ['record' => $customer->id])
        ->fillForm(['name' => 'Jane Doe', 'email' => 'jane@example.com'])
        ->call('save')
        ->assertHasNoFormErrors();

    assertDatabaseHas(Customer::class, [
        'id' => $customer->id,
        'name' => 'Jane Doe',
    ]);
});

it('validates required fields on create', function () {
    Livewire::test(CreateCustomer::class)
        ->fillForm(['name' => '', 'email' => ''])
        ->call('create')
        ->assertHasFormErrors(['name' => 'required', 'email' => 'required']);
});

it('can soft-delete a customer', function () {
    $customer = Customer::factory()->create();

    Livewire::test(ListCustomers::class)
        ->callAction(TestAction::make('delete')->table($customer));

    assertSoftDeleted(Customer::class, ['id' => $customer->id]);
});

it('can restore a soft-deleted customer', function () {
    $customer = Customer::factory()->create();
    $customer->delete();

    Livewire::test(ListCustomers::class)
        ->callAction(TestAction::make('restore')->table($customer));

    assertDatabaseHas(Customer::class, ['id' => $customer->id, 'deleted_at' => null]);
});

it('can search customers by name', function () {
    $customer = Customer::factory()->create(['name' => 'Alice Wonderland']);
    $other = Customer::factory()->create(['name' => 'Bob Builder']);

    Livewire::test(ListCustomers::class)
        ->searchTable('Alice')
        ->assertCanSeeTableRecords([$customer])
        ->assertCanNotSeeTableRecords([$other]);
});
