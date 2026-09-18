<?php

namespace App\Data\Bank;

use Spatie\LaravelData\Data;

class LoginResultData extends Data
{
    public function __construct(
        public bool $requires_otp,
        public ?string $customer_name = null,
        public ?string $otp_prompt = null,
    ) {}

    public static function authenticated(?string $customerName = null): self
    {
        return new self(requires_otp: false, customer_name: $customerName);
    }

    public static function pendingOtp(string $prompt): self
    {
        return new self(requires_otp: true, otp_prompt: $prompt);
    }
}
