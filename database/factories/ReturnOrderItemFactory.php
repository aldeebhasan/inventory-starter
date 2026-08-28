<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ReturnOrder;
use App\Models\ReturnOrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReturnOrderItem>
 */
class ReturnOrderItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'return_order_id' => ReturnOrder::factory(),
            'product_id' => Product::factory(),
            'quantity' => fake()->randomFloat(3, 1, 50),
            'price' => fake()->randomFloat(3, 1, 1000),
        ];
    }
}
