<?php

namespace App\Data\Bank;

use Spatie\LaravelData\Data;

class VerifyOtpRequestData extends Data
{
    public function __construct(
        public string $verification_reference,
        public string $customer_id,
        public string $verification_code,
    ) {}
}
