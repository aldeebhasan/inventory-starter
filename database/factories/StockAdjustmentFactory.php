<?php

namespace Database\Factories;

use App\Enums\StockAdjustmentStatus;
use App\Models\Location;
use App\Models\StockAdjustment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockAdjustment>
 */
class StockAdjustmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'location_id' => fn () => Location::query()->create([
                'name' => fake()->city(),
                'is_active' => true,
                'meta' => ['type' => 'warehouse'],
            ])->id,
            'reason' => fake()->sentence(4),
            'notes' => null,
            'status' => StockAdjustmentStatus::Draft,
            'created_by' => null,
        ];
    }

    public function processing(): static
    {
        return $this->state(['status' => StockAdjustmentStatus::Processing]);
    }

    public function applied(): static
    {
        return $this->state(['status' => StockAdjustmentStatus::Applied]);
    }

    public function partiallyApplied(): static
    {
        return $this->state(['status' => StockAdjustmentStatus::PartiallyApplied]);
    }

    public function cancelled(): static
    {
        return $this->state(['status' => StockAdjustmentStatus::Cancelled]);
    }
}
