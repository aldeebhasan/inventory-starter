<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\SaleOrder;
use App\Models\SaleOrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SaleOrderItem>
 */
class SaleOrderItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'sale_order_id' => SaleOrder::factory(),
            'product_id' => Product::factory(),
            'quantity' => fake()->randomFloat(3, 1, 50),
            'unit_price' => fake()->randomFloat(3, 1, 1000),
            'reservation_id' => null,
        ];
    }
}
