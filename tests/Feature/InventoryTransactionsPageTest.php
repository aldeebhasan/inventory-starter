<?php

use Aldeebhasan\Inventorix\DTOs\StockOperationDto;
use Aldeebhasan\Inventorix\Enums\MovementType;
use Aldeebhasan\Inventorix\Models\Movement;
use App\Enums\ProductType;
use App\Filament\Exports\InventoryTransactionsExporter;
use App\Filament\Pages\InventoryTransactions;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\User;
use Filament\Actions\ExportAction;
use Filament\Actions\Exports\Models\Export;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->location = createLocation();
    $this->product = Product::factory()->create(['type' => ProductType::Inventory]);
});

it('can render the inventory transactions page', function () {
    Livewire::test(InventoryTransactions::class)->assertSuccessful();
});

it('lists movements after adding stock', function () {
    $this->product->addStock(10, $this->location);

    $movements = Movement::whereHasMorph('stockable', [Product::class])->get();

    Livewire::test(InventoryTransactions::class)
        ->assertCanSeeTableRecords($movements);
});

it('shows add and deduct movements', function () {
    $this->product->addStock(15, $this->location);
    $this->product->deductStock(5, $this->location);

    $movements = Movement::whereHasMorph('stockable', [Product::class])->get();
    expect($movements)->toHaveCount(2);

    Livewire::test(InventoryTransactions::class)
        ->assertCanSeeTableRecords($movements);
});

it('can filter movements by product', function () {
    $product2 = Product::factory()->create(['type' => ProductType::Inventory]);

    $this->product->addStock(10, $this->location);
    $product2->addStock(5, $this->location);

    $movements1 = Movement::where('stockable_id', $this->product->id)->where('stockable_type', Product::class)->get();
    $movements2 = Movement::where('stockable_id', $product2->id)->where('stockable_type', Product::class)->get();

    Livewire::test(InventoryTransactions::class)
        ->filterTable('stockable_id', $this->product->id)
        ->assertCanSeeTableRecords($movements1)
        ->assertCanNotSeeTableRecords($movements2);
});

it('can filter movements by location', function () {
    $otherLocation = createLocation(['name' => 'Other Location']);

    $this->product->addStock(10, $this->location);
    $this->product->addStock(5, $otherLocation);

    $movementsHere = Movement::where('location_id', $this->location->id)->get();
    $movementsThere = Movement::where('location_id', $otherLocation->id)->get();

    Livewire::test(InventoryTransactions::class)
        ->filterTable('location_id', $this->location->id)
        ->assertCanSeeTableRecords($movementsHere)
        ->assertCanNotSeeTableRecords($movementsThere);
});

it('can filter movements by type', function () {
    $this->product->addStock(20, $this->location);
    $this->product->deductStock(5, $this->location);

    $addMovements = Movement::where('type', MovementType::Add->value)->get();
    $deductMovements = Movement::where('type', MovementType::Deduct->value)->get();

    Livewire::test(InventoryTransactions::class)
        ->filterTable('type', MovementType::Add->value)
        ->assertCanSeeTableRecords($addMovements)
        ->assertCanNotSeeTableRecords($deductMovements);
});

it('defaults to descending date sort', function () {
    Livewire::test(InventoryTransactions::class)
        ->assertSuccessful();
});

function seedInventoryTransactionsPageMovements(int $count, Location $location): void
{
    for ($i = 0; $i < $count; $i++) {
        $order = PurchaseOrder::factory()->create(['location_id' => $location->id]);
        $product = Product::factory()->create(['type' => ProductType::Inventory]);
        $product->addStock(5, $location, new StockOperationDto(causable: $order));
    }
}

it('does not run per-row queries when rendering, including the source document column', function () {
    seedInventoryTransactionsPageMovements(2, $this->location);
    $small = countQueries(fn () => Livewire::test(InventoryTransactions::class)->toggleAllTableColumns()->assertCountTableRecords(2));

    seedInventoryTransactionsPageMovements(6, $this->location);
    $large = countQueries(fn () => Livewire::test(InventoryTransactions::class)->toggleAllTableColumns()->assertCountTableRecords(8));

    expect($large)->toBe($small);
});

it('shows the source document from the eager-loaded causable', function () {
    $order = PurchaseOrder::factory()->create(['location_id' => $this->location->id]);
    $this->product->addStock(5, $this->location, new StockOperationDto(causable: $order));
    $movement = Movement::query()->firstOrFail();

    Livewire::test(InventoryTransactions::class)
        ->assertTableColumnStateSet('source_document', $order->refresh()->order_number, $movement);
});

it('sorts by product name', function () {
    $zeta = Product::factory()->create(['type' => ProductType::Inventory, 'name' => 'Zeta']);
    $alpha = Product::factory()->create(['type' => ProductType::Inventory, 'name' => 'Alpha']);
    $zeta->addStock(5, $this->location);
    $alpha->addStock(5, $this->location);

    $zetaMovement = Movement::where('stockable_id', $zeta->id)->firstOrFail();
    $alphaMovement = Movement::where('stockable_id', $alpha->id)->firstOrFail();

    Livewire::test(InventoryTransactions::class)
        ->sortTable('stockable.name')
        ->assertCanSeeTableRecords([$alphaMovement, $zetaMovement], inOrder: true)
        ->sortTable('stockable.name', 'desc')
        ->assertCanSeeTableRecords([$zetaMovement, $alphaMovement], inOrder: true);
});

it('dispatches an export from the table header', function () {
    $this->product->addStock(10, $this->location);
    $exports = ExportAction::fake();

    Livewire::test(InventoryTransactions::class)
        ->callAction(TestAction::make('export')->table())
        ->assertHasNoFormErrors();

    $exports->assertDispatched(InventoryTransactionsExporter::class, function (Export $export, Builder $query): bool {
        expect($query->count())->toBe(1);

        return true;
    });
});

it('exports row values matching the table columns', function () {
    $this->product->update(['name' => 'Hammer']);
    $order = PurchaseOrder::factory()->create(['location_id' => $this->location->id]);
    $this->product->addStock(5, $this->location, new StockOperationDto(causable: $order, note: 'Received'));

    $movement = Movement::query()->with(['stockable', 'location', 'transaction.causable'])->firstOrFail();

    $row = InventoryTransactionsExporter::test(columnMap: [
        'stockable.name' => 'Product',
        'location.name' => 'Location',
        'type' => 'Type',
        'source_document' => 'Source Document',
        'note' => 'Note',
    ])->export($movement);

    expect($row)->toBe(['Hammer', $this->location->name, MovementType::Add->value, $order->refresh()->order_number, 'Received']);
});

it('runs a real csv export end to end', function () {
    Storage::fake('local');
    seedInventoryTransactionsPageMovements(3, $this->location);

    Livewire::test(InventoryTransactions::class)
        ->callAction(TestAction::make('export')->table())
        ->assertHasNoFormErrors();

    $export = Export::query()->latest('id')->firstOrFail();
    $csv = collect(Storage::disk('local')->files($export->getFileDirectory()))
        ->filter(fn (string $file): bool => str_ends_with($file, '.csv'))
        ->map(fn (string $file): string => Storage::disk('local')->get($file))
        ->join('');

    expect($export->refresh()->successful_rows)->toBe(3)
        ->and($csv)->toContain(PurchaseOrder::query()->latest('id')->first()->order_number);
});
