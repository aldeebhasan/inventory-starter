<?php

namespace Database\Factories;

use App\Enums\PurchaseOrderStatus;
use App\Models\Location;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseOrder>
 */
class PurchaseOrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'supplier_id' => Supplier::factory(),
            'location_id' => fn () => Location::query()->create([
                'name' => fake()->city(),
                'is_active' => true,
                'meta' => ['type' => 'warehouse'],
            ])->id,
            'status' => PurchaseOrderStatus::Draft,
            'ordered_at' => now(),
            'received_at' => null,
            'notes' => null,
            'created_by' => null,
        ];
    }

    public function confirmed(): static
    {
        return $this->state(['status' => PurchaseOrderStatus::Confirmed]);
    }

    public function received(): static
    {
        return $this->state(['status' => PurchaseOrderStatus::Received, 'received_at' => now()]);
    }

    public function cancelled(): static
    {
        return $this->state(['status' => PurchaseOrderStatus::Cancelled]);
    }
}
