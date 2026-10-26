<?php

use App\Filament\Exports\SupplierPerformanceExporter;
use App\Filament\Pages\SupplierPerformance;
use App\Models\Location;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\User;
use Filament\Actions\ExportAction;
use Filament\Actions\Exports\Models\Export;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->location = createLocation();
    $this->supplier = Supplier::factory()->create();
});

it('can render the supplier performance page', function () {
    Livewire::test(SupplierPerformance::class)->assertSuccessful();
});

it('lists suppliers', function () {
    $suppliers = Supplier::factory()->count(3)->create();

    Livewire::test(SupplierPerformance::class)
        ->assertCanSeeTableRecords($suppliers->push($this->supplier));
});

it('shows supplier with purchase order metrics', function () {
    $po = PurchaseOrder::factory()->received()->create([
        'supplier_id' => $this->supplier->id,
        'location_id' => $this->location->id,
    ]);

    PurchaseOrderItem::factory()->create([
        'purchase_order_id' => $po->id,
        'unit_cost' => 10.00,
        'quantity' => 50,
    ]);

    Livewire::test(SupplierPerformance::class)
        ->assertCanSeeTableRecords([$this->supplier]);
});

it('can filter by supplier', function () {
    $supplier2 = Supplier::factory()->create();

    Livewire::test(SupplierPerformance::class)
        ->filterTable('supplier_id', $this->supplier->id)
        ->assertCanSeeTableRecords([$this->supplier])
        ->assertCanNotSeeTableRecords([$supplier2]);
});

it('counts received and cancelled POs separately', function () {
    PurchaseOrder::factory()->received()->create([
        'supplier_id' => $this->supplier->id,
        'location_id' => $this->location->id,
    ]);
    PurchaseOrder::factory()->cancelled()->create([
        'supplier_id' => $this->supplier->id,
        'location_id' => $this->location->id,
    ]);

    Livewire::test(SupplierPerformance::class)->assertSuccessful();
});

it('calculates total spend from received POs', function () {
    $po = PurchaseOrder::factory()->received()->create([
        'supplier_id' => $this->supplier->id,
        'location_id' => $this->location->id,
    ]);

    PurchaseOrderItem::factory()->create([
        'purchase_order_id' => $po->id,
        'unit_cost' => 25.00,
        'quantity' => 10,
    ]);

    Livewire::test(SupplierPerformance::class)->assertSuccessful();
});

/**
 * A received PO for the supplier, ordered $daysAgo days ago, with one line of 10 units at $unitCost.
 */
function receivedPoForPerformance(Supplier $supplier, Location $location, float $unitCost, int $daysAgo = 1): PurchaseOrder
{
    $po = PurchaseOrder::factory()->received()->create([
        'supplier_id' => $supplier->id,
        'location_id' => $location->id,
        'ordered_at' => now()->subDays($daysAgo),
    ]);
    PurchaseOrderItem::factory()->create(['purchase_order_id' => $po->id, 'unit_cost' => $unitCost, 'quantity' => 10]);

    return $po;
}

it('does not run per-row queries', function () {
    $createSuppliers = function (int $count): void {
        Supplier::factory()->count($count)->create()
            ->each(fn (Supplier $supplier) => receivedPoForPerformance($supplier, $this->location, 5));
    };

    $createSuppliers(1);
    $small = countQueries(fn () => Livewire::test(SupplierPerformance::class)->assertCountTableRecords(2));

    $createSuppliers(8);
    $large = countQueries(fn () => Livewire::test(SupplierPerformance::class)->assertCountTableRecords(10));

    expect($large)->toBe($small);
});

it('scopes the metrics to the date range filter', function () {
    receivedPoForPerformance($this->supplier, $this->location, 10, daysAgo: 40);
    receivedPoForPerformance($this->supplier, $this->location, 3, daysAgo: 2);

    Livewire::test(SupplierPerformance::class)
        ->assertTableColumnStateSet('total_pos', 2, $this->supplier)
        ->assertTableColumnStateSet('total_spend', '130.00', $this->supplier)
        ->filterTable('date_range', ['from' => now()->subDays(7)->toDateString(), 'until' => now()->toDateString()])
        ->assertTableColumnStateSet('total_pos', 1, $this->supplier)
        ->assertTableColumnStateSet('total_spend', '30.00', $this->supplier);
});

it('sorts by total spend', function () {
    $big = Supplier::factory()->create();
    receivedPoForPerformance($big, $this->location, 100);
    receivedPoForPerformance($this->supplier, $this->location, 1);

    Livewire::test(SupplierPerformance::class)
        ->sortTable('total_spend', 'asc')
        ->assertCanSeeTableRecords([$this->supplier, $big], inOrder: true)
        ->sortTable('total_spend', 'desc')
        ->assertCanSeeTableRecords([$big, $this->supplier], inOrder: true);
});

it('dispatches the supplier performance export', function () {
    $exports = ExportAction::fake();

    Livewire::test(SupplierPerformance::class)
        ->callAction(TestAction::make('export')->table())
        ->assertHasNoFormErrors();

    $exports->assertDispatched(SupplierPerformanceExporter::class);
});

it('exports computed row values', function () {
    $this->supplier->update(['name' => 'Acme Supply']);
    receivedPoForPerformance($this->supplier, $this->location, 4);
    PurchaseOrder::factory()->cancelled()->create(['supplier_id' => $this->supplier->id, 'location_id' => $this->location->id]);

    $record = Livewire::test(SupplierPerformance::class)->instance()->getTableQueryForExport()->findOrFail($this->supplier->id);

    expect(SupplierPerformanceExporter::test()->export($record))
        ->toBe(['Acme Supply', '2', '1', '1', '50', '1', '10', '40', '4']);
});

it('runs a real export of the aggregated rows', function () {
    Storage::fake('local');
    $suppliers = Supplier::factory()->count(2)->create()->push($this->supplier);
    $suppliers->each(fn (Supplier $supplier) => receivedPoForPerformance($supplier, $this->location, 5));

    Livewire::test(SupplierPerformance::class)
        ->callAction(TestAction::make('export')->table())
        ->assertHasNoFormErrors();

    $export = Export::query()->latest('id')->firstOrFail();
    $csv = collect(Storage::disk('local')->files($export->getFileDirectory()))
        ->filter(fn (string $file): bool => str_ends_with($file, '.csv') && ! str_ends_with($file, 'headers.csv'))
        ->map(fn (string $file): string => (string) Storage::disk('local')->get($file))
        ->implode('');

    expect($export->successful_rows)->toBe(3)
        ->and($csv)->toContain(...$suppliers->pluck('name')->all());
});
