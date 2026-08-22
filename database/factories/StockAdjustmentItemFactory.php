<?php

namespace Database\Factories;

use App\Enums\StockAdjustmentItemStatus;
use App\Enums\StockAdjustmentOperation;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockAdjustmentItem>
 */
class StockAdjustmentItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'stock_adjustment_id' => StockAdjustment::factory(),
            'product_id' => Product::factory(),
            'operation' => StockAdjustmentOperation::Increase,
            'quantity' => fake()->randomFloat(2, 1, 100),
            'current_stock' => null,
            'item_status' => StockAdjustmentItemStatus::Pending,
            'failure_reason' => null,
        ];
    }

    public function increase(): static
    {
        return $this->state(['operation' => StockAdjustmentOperation::Increase]);
    }

    public function decrease(): static
    {
        return $this->state(['operation' => StockAdjustmentOperation::Decrease]);
    }

    public function adjust(): static
    {
        return $this->state(['operation' => StockAdjustmentOperation::Adjust]);
    }

    public function applied(): static
    {
        return $this->state(['item_status' => StockAdjustmentItemStatus::Applied]);
    }

    public function failed(string $reason = 'Insufficient stock'): static
    {
        return $this->state([
            'item_status' => StockAdjustmentItemStatus::Failed,
            'failure_reason' => $reason,
        ]);
    }
}
