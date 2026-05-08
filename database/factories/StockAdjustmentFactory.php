<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class StockAdjustmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => 'ADJ-'.now()->format('Ymd').'-'.fake()->unique()->numerify('####'),
            'created_by' => User::factory()->admin(),
            'approved_by' => null,
            'adjustment_date' => now()->toDateString(),
            'status' => 'draft',
            'reason' => fake()->sentence(),
        ];
    }
}
