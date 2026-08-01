<?php

namespace Database\Factories;

use App\Models\Addon;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Addon>
 */
class AddonFactory extends Factory
{
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'name' => fake()->words(2, true),
            'price' => fake()->randomFloat(3, 1, 100),
            'description' => fake()->sentence(),
            'is_active' => true,
        ];
    }
}
