<?php

namespace Database\Factories;

use App\Enums\ReturnOrderStatus;
use App\Enums\ReturnOrderType;
use App\Models\Customer;
use App\Models\Location;
use App\Models\ReturnOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReturnOrder>
 */
class ReturnOrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'type' => ReturnOrderType::CustomerReturn,
            'customer_id' => Customer::factory(),
            'supplier_id' => null,
            'location_id' => fn () => Location::query()->create([
                'name' => fake()->city(),
                'is_active' => true,
                'meta' => ['type' => 'warehouse'],
            ])->id,
            'status' => ReturnOrderStatus::Draft,
            'reason' => fake()->sentence(),
            'notes' => null,
            'created_by' => null,
        ];
    }

    public function customerReturn(): static
    {
        return $this->state([
            'type' => ReturnOrderType::CustomerReturn,
        ]);
    }

    public function supplierReturn(): static
    {
        return $this->state([
            'type' => ReturnOrderType::SupplierReturn,
            'customer_id' => null,
        ]);
    }

    public function completed(): static
    {
        return $this->state(['status' => ReturnOrderStatus::Completed]);
    }

    public function cancelled(): static
    {
        return $this->state(['status' => ReturnOrderStatus::Cancelled]);
    }
}
