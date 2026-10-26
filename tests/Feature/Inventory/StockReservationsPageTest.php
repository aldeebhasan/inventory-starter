<?php

use Aldeebhasan\Inventorix\DTOs\StockOperationDto;
use Aldeebhasan\Inventorix\Enums\ReservationStatus;
use Aldeebhasan\Inventorix\Models\Reservation;
use App\Enums\ProductType;
use App\Filament\Exports\StockReservationsExporter;
use App\Filament\Pages\StockReservations;
use App\Models\Location;
use App\Models\Product;
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
    $this->product->addStock(100, $this->location);
});

function seedStockReservationsPageRows(int $count, Location $location): void
{
    for ($i = 0; $i < $count; $i++) {
        $product = Product::factory()->create(['type' => ProductType::Inventory]);
        $product->addStock(10, $location);
        $product->reserve(2, $location->id);
    }
}

it('can render the stock reservations page', function () {
    Livewire::test(StockReservations::class)->assertSuccessful();
});

it('lists product reservations', function () {
    $reservation = $this->product->reserve(5, $this->location->id);

    Livewire::test(StockReservations::class)
        ->assertCanSeeTableRecords([$reservation])
        ->assertTableColumnStateSet('quantity', '5.0000', $reservation);
});

it('can filter by product', function () {
    $reservation = $this->product->reserve(5, $this->location->id);
    $other = Product::factory()->create(['type' => ProductType::Inventory]);
    $other->addStock(10, $this->location);
    $otherReservation = $other->reserve(3, $this->location->id);

    Livewire::test(StockReservations::class)
        ->filterTable('stockable_id', $this->product->id)
        ->assertCanSeeTableRecords([$reservation])
        ->assertCanNotSeeTableRecords([$otherReservation]);
});

it('can filter by location', function () {
    $otherLocation = createLocation();
    $reservation = $this->product->reserve(5, $this->location->id);
    $this->product->addStock(10, $otherLocation);
    $otherReservation = $this->product->reserve(3, $otherLocation->id);

    Livewire::test(StockReservations::class)
        ->filterTable('location_id', $this->location->id)
        ->assertCanSeeTableRecords([$reservation])
        ->assertCanNotSeeTableRecords([$otherReservation]);
});

it('can filter by status', function () {
    $pending = $this->product->reserve(5, $this->location->id);
    $released = $this->product->reserve(3, $this->location->id);
    $this->product->releaseReservation($released);

    Livewire::test(StockReservations::class)
        ->filterTable('status', ReservationStatus::Pending->value)
        ->assertCanSeeTableRecords([$pending])
        ->assertCanNotSeeTableRecords([$released->refresh()]);
});

it('can filter by date range', function () {
    $old = $this->product->reserve(5, $this->location->id);
    $old->forceFill(['created_at' => now()->subMonth()])->save();
    $recent = $this->product->reserve(3, $this->location->id);

    Livewire::test(StockReservations::class)
        ->filterTable('date_range', ['from' => now()->subDay()->toDateString(), 'until' => null])
        ->assertCanSeeTableRecords([$recent])
        ->assertCanNotSeeTableRecords([$old]);
});

it('sorts by product name', function () {
    $zeta = Product::factory()->create(['type' => ProductType::Inventory, 'name' => 'Zeta']);
    $alpha = Product::factory()->create(['type' => ProductType::Inventory, 'name' => 'Alpha']);
    $zeta->addStock(5, $this->location);
    $alpha->addStock(5, $this->location);
    $zetaReservation = $zeta->reserve(1, $this->location->id);
    $alphaReservation = $alpha->reserve(1, $this->location->id);

    Livewire::test(StockReservations::class)
        ->sortTable('stockable.name')
        ->assertCanSeeTableRecords([$alphaReservation, $zetaReservation], inOrder: true)
        ->sortTable('stockable.name', 'desc')
        ->assertCanSeeTableRecords([$zetaReservation, $alphaReservation], inOrder: true);
});

it('does not run per-row queries when rendering', function () {
    seedStockReservationsPageRows(2, $this->location);
    $small = countQueries(fn () => Livewire::test(StockReservations::class)->assertCountTableRecords(2));

    seedStockReservationsPageRows(6, $this->location);
    $large = countQueries(fn () => Livewire::test(StockReservations::class)->assertCountTableRecords(8));

    expect($large)->toBe($small);
});

it('dispatches an export from the table header', function () {
    seedStockReservationsPageRows(2, $this->location);
    $exports = ExportAction::fake();

    Livewire::test(StockReservations::class)
        ->callAction(TestAction::make('export')->table())
        ->assertHasNoFormErrors();

    $exports->assertDispatched(StockReservationsExporter::class, function (Export $export, Builder $query): bool {
        expect($query->count())->toBe(2);

        return true;
    });
});

it('exports row values matching the table columns', function () {
    $this->product->update(['name' => 'Hammer']);
    $reservation = $this->product->reserve(5, $this->location->id, new StockOperationDto(note: 'For order'));

    $row = StockReservationsExporter::test(columnMap: [
        'stockable.name' => 'Product',
        'location.name' => 'Location',
        'quantity' => 'Qty',
        'status' => 'Status',
        'note' => 'Note',
    ])->export(Reservation::query()->with(['stockable', 'location'])->findOrFail($reservation->id));

    expect($row)->toBe(['Hammer', $this->location->name, '5.0000', ReservationStatus::Pending->value, 'For order']);
});

it('runs a real csv export end to end', function () {
    Storage::fake('local');
    seedStockReservationsPageRows(3, $this->location);

    Livewire::test(StockReservations::class)
        ->callAction(TestAction::make('export')->table())
        ->assertHasNoFormErrors();

    $export = Export::query()->latest('id')->firstOrFail();
    $csv = collect(Storage::disk('local')->files($export->getFileDirectory()))
        ->filter(fn (string $file): bool => str_ends_with($file, '.csv'))
        ->map(fn (string $file): string => Storage::disk('local')->get($file))
        ->join('');

    expect($export->refresh()->successful_rows)->toBe(3)
        ->and($csv)->toContain(Product::query()->latest('id')->first()->name);
});
