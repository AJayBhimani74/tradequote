<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::factory()->create([
            'name' => 'Demo Buyer',
            'email' => 'buyer@example.com',
            'role' => Role::Buyer,
        ]);

        foreach (['Asha Fasteners' => 'vendor1@example.com', 'Kiran Electricals' => 'vendor2@example.com'] as $company => $email) {
            $vendor = Vendor::factory()->create([
                'company_name' => $company,
                'user_id' => User::factory()->create([
                    'name' => $company,
                    'email' => $email,
                    'role' => Role::Vendor,
                ]),
            ]);

            Product::factory()->count(12)->create(['vendor_id' => $vendor->id]);
        }
    }
}
