<?php

namespace Database\Factories;

use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductFactory extends Factory
{
    public function definition(): array
    {
        return [
            'vendor_id' => Vendor::factory(),
            'sku' => strtoupper(fake()->unique()->bothify('??-####')),
            'name' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'unit' => 'pcs',
            'price' => fake()->randomFloat(2, 5, 500),
            'stock' => fake()->numberBetween(0, 500),
            'is_active' => true,
        ];
    }
}
