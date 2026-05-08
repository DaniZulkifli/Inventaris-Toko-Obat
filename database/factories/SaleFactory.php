<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class SaleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'invoice_number' => 'INV-'.now()->format('Ymd').'-'.fake()->unique()->numerify('####'),
            'cashier_id' => User::factory(),
            'sale_date' => now(),
            'customer_name' => 'Pelanggan Umum',
            'payment_method' => 'cash',
            'status' => 'completed',
            'subtotal' => 0,
            'discount' => 0,
            'total_amount' => 0,
            'amount_paid' => 0,
            'change_amount' => 0,
            'gross_margin' => 0,
            'notes' => fake()->optional()->sentence(),
        ];
    }
}
