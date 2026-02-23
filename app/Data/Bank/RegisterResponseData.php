<?php

namespace App\Data\Bank;

use Spatie\LaravelData\Data;

class RegisterResponseData extends Data
{
    public function __construct(
        public string $verification_reference,
        public CustomerData $customer,
    ) {}
}
