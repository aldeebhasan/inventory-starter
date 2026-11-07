<?php

namespace Database\Factories;

use App\Models\ReportDefinition;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReportDefinition>
 */
class ReportDefinitionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->unique()->sentence(3),
            'description' => null,
            'is_shared' => false,
            'definition' => [
                'base' => 'products',
                'columns' => [
                    ['field' => 'products.name', 'label' => 'Product', 'format' => 'text', 'total' => false],
                ],
            ],
        ];
    }

    public function shared(): static
    {
        return $this->state(['is_shared' => true]);
    }
}
