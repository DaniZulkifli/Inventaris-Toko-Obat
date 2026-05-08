<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class StockUsageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => 'USE-'.now()->format('Ymd').'-'.fake()->unique()->numerify('####'),
            'created_by' => User::factory()->admin(),
            'completed_by' => null,
            'usage_date' => now()->toDateString(),
            'usage_type' => 'damaged',
            'status' => 'draft',
            'estimated_total_cost' => 0,
            'reason' => fake()->sentence(),
        ];
    }
}
