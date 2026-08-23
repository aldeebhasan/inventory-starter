<?php

namespace Database\Factories;

use App\Enums\TransferOrderItemStatus;
use App\Models\Product;
use App\Models\TransferOrder;
use App\Models\TransferOrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TransferOrderItem>
 */
class TransferOrderItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'transfer_order_id' => TransferOrder::factory(),
            'product_id' => Product::factory(),
            'quantity' => fake()->randomFloat(3, 1, 100),
            'item_status' => TransferOrderItemStatus::Pending,
            'failure_reason' => null,
        ];
    }

    public function sent(): static
    {
        return $this->state(['item_status' => TransferOrderItemStatus::Sent]);
    }

    public function received(): static
    {
        return $this->state(['item_status' => TransferOrderItemStatus::Received]);
    }

    public function failed(string $reason = 'Test failure'): static
    {
        return $this->state([
            'item_status' => TransferOrderItemStatus::Failed,
            'failure_reason' => $reason,
        ]);
    }
}
