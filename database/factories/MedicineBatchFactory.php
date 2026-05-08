<?php

namespace Database\Factories;

use App\Models\Medicine;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

class MedicineBatchFactory extends Factory
{
    public function definition(): array
    {
        $stock = fake()->randomFloat(3, 1, 200);

        return [
            'medicine_id' => Medicine::factory(),
            'supplier_id' => Supplier::factory(),
            'batch_number' => 'BAT-'.fake()->unique()->bothify('???-####'),
            'expiry_date' => now()->addMonths(fake()->numberBetween(3, 24))->toDateString(),
            'purchase_price' => fake()->randomFloat(2, 100, 50000),
            'selling_price' => null,
            'initial_stock' => $stock,
            'current_stock' => $stock,
            'received_date' => now()->toDateString(),
            'status' => 'available',
            'notes' => fake()->optional()->sentence(),
        ];
    }
}
