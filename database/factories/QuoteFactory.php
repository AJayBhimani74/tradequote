<?php

namespace Database\Factories;

use App\Enums\QuoteStatus;
use App\Enums\Role;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

class QuoteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'buyer_id' => User::factory()->state(['role' => Role::Buyer]),
            'vendor_id' => Vendor::factory(),
            'status' => QuoteStatus::Requested,
        ];
    }
}
