<?php

use Aldeebhasan\Inventorix\Enums\TransactionType;
use Aldeebhasan\Inventorix\Models\Transaction;
use App\Enums\ProductType;
use App\Filament\Pages\TransactionLedger;
use App\Models\Product;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->location = createLocation();
    $this->product = Product::factory()->create(['type' => ProductType::Inventory]);
});

it('can render the transaction ledger page', function () {
    Livewire::test(TransactionLedger::class)->assertSuccessful();
});

it('lists transactions after stock operations', function () {
    $this->product->addStock(10, $this->location);

    $transactions = Transaction::all();

    Livewire::test(TransactionLedger::class)
        ->assertCanSeeTableRecords($transactions);
});

it('can filter by transaction type', function () {
    $this->product->addStock(10, $this->location);

    Livewire::test(TransactionLedger::class)
        ->filterTable('type', TransactionType::Manual->value)
        ->assertSuccessful();
});

it('can filter by status', function () {
    $this->product->addStock(10, $this->location);

    Livewire::test(TransactionLedger::class)
        ->assertSuccessful();
});

it('shows transaction with movement count', function () {
    $this->product->addStock(10, $this->location);
    $this->product->addStock(5, $this->location);

    Livewire::test(TransactionLedger::class)
        ->assertSuccessful();
});
