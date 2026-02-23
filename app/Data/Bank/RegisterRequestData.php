<?php

namespace App\Data\Bank;

use Spatie\LaravelData\Data;

class RegisterRequestData extends Data
{
    public function __construct(
        public string $customer_id,
        public string $national_id,
        public string $phone_number,
        public string $device_id,
        public string $password,
    ) {}
}
