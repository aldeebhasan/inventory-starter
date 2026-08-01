<?php

namespace Database\Factories;

use App\Enums\SaleOrderStatus;
use App\Models\Customer;
use App\Models\Location;
use App\Models\SaleOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SaleOrder>
 */
class SaleOrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'location_id' => fn () => Location::create([
                'name' => fake()->city(),
                'is_active' => true,
                'meta' => ['type' => 'warehouse'],
            ])->id,
            'status' => SaleOrderStatus::Draft,
            'ordered_at' => now(),
            'shipped_at' => null,
            'notes' => null,
            'created_by' => null,
        ];
    }

    public function confirmed(): static
    {
        return $this->state(['status' => SaleOrderStatus::Confirmed]);
    }

    public function picked(): static
    {
        return $this->state(['status' => SaleOrderStatus::Picked]);
    }

    public function shipped(): static
    {
        return $this->state(['status' => SaleOrderStatus::Shipped, 'shipped_at' => now()]);
    }

    public function cancelled(): static
    {
        return $this->state(['status' => SaleOrderStatus::Cancelled]);
    }
}
