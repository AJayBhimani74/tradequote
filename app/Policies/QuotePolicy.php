<?php

namespace App\Policies;

use App\Models\Quote;
use App\Models\User;

class QuotePolicy
{
    public function view(User $user, Quote $quote): bool
    {
        return $this->isBuyer($user, $quote) || $this->isVendor($user, $quote);
    }

    public function respond(User $user, Quote $quote): bool
    {
        return $this->isVendor($user, $quote);
    }

    public function decide(User $user, Quote $quote): bool
    {
        return $this->isBuyer($user, $quote);
    }

    private function isBuyer(User $user, Quote $quote): bool
    {
        return $quote->buyer_id === $user->id;
    }

    private function isVendor(User $user, Quote $quote): bool
    {
        return $user->vendor !== null && $user->vendor->id === $quote->vendor_id;
    }
}
