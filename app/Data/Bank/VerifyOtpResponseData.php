<?php

namespace App\Data\Bank;

use Spatie\LaravelData\Data;

class VerifyOtpResponseData extends Data
{
    public function __construct(
        public string $access_token,
        public string $refresh_token,
        public string $expires_at,
        public string $refresh_token_expires_at,
        public CustomerData $customer,
    ) {}
}
