<?php

namespace Database\Factories;

use App\Enums\TransferOrderStatus;
use App\Models\Location;
use App\Models\TransferOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TransferOrder>
 */
class TransferOrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'from_location_id' => fn () => Location::query()->create([
                'name' => fake()->city().' Warehouse',
                'is_active' => true,
                'meta' => ['type' => 'warehouse'],
            ])->id,
            'to_location_id' => fn () => Location::query()->create([
                'name' => fake()->city().' Warehouse',
                'is_active' => true,
                'meta' => ['type' => 'warehouse'],
            ])->id,
            'status' => TransferOrderStatus::Draft,
            'notes' => null,
            'created_by' => null,
        ];
    }

    public function confirmed(): static
    {
        return $this->state(['status' => TransferOrderStatus::Confirmed]);
    }

    public function sending(): static
    {
        return $this->state(['status' => TransferOrderStatus::Sending]);
    }

    public function inTransit(): static
    {
        return $this->state(['status' => TransferOrderStatus::InTransit]);
    }

    public function completed(): static
    {
        return $this->state(['status' => TransferOrderStatus::Completed]);
    }

    public function cancelled(): static
    {
        return $this->state(['status' => TransferOrderStatus::Cancelled]);
    }
}
