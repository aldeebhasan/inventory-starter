<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'price' => fake()->randomFloat(3, 1, 1000),
            'cost' => fake()->randomFloat(3, 0.5, 500),
            'image' => null,
            'brand_id' => null,
            'unit_id' => null,
        ];
    }
}
