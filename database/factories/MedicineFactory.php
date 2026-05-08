<?php

namespace Database\Factories;

use App\Models\DosageForm;
use App\Models\MedicineCategory;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

class MedicineFactory extends Factory
{
    public function definition(): array
    {
        return [
            'medicine_category_id' => MedicineCategory::factory(),
            'unit_id' => Unit::factory(),
            'dosage_form_id' => DosageForm::factory(),
            'code' => 'MED-'.now()->format('Ymd').'-'.fake()->unique()->numerify('####'),
            'barcode' => fake()->boolean(70) ? fake()->unique()->ean13() : null,
            'name' => fake()->unique()->words(4, true),
            'generic_name' => fake()->optional()->words(2, true),
            'manufacturer' => fake()->optional()->company(),
            'registration_number' => fake()->optional()->bothify('???########'),
            'active_ingredient' => fake()->optional()->words(3, true),
            'strength' => fake()->optional()->randomElement(['250 mg', '500 mg', '100 ml', '1%']),
            'classification' => 'obat_bebas',
            'requires_prescription' => false,
            'default_purchase_price' => fake()->randomFloat(2, 100, 50000),
            'selling_price' => fake()->randomFloat(2, 500, 75000),
            'minimum_stock' => fake()->randomFloat(3, 1, 50),
            'reorder_level' => fake()->randomFloat(3, 1, 100),
            'storage_instruction' => fake()->optional()->sentence(),
            'image_path' => null,
            'is_active' => true,
        ];
    }
}
