<?php

namespace Database\Factories;

use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PurchaseOrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => 'PO-'.now()->format('Ymd').'-'.fake()->unique()->numerify('####'),
            'supplier_id' => Supplier::factory(),
            'created_by' => User::factory()->admin(),
            'received_by' => null,
            'order_date' => now()->toDateString(),
            'received_date' => null,
            'status' => 'draft',
            'subtotal' => 0,
            'discount' => 0,
            'total_amount' => 0,
            'notes' => fake()->optional()->sentence(),
        ];
    }
}
