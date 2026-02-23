<?php

namespace App\Data\Bank;

use Spatie\LaravelData\Data;

class ProfileResponseData extends Data
{
    public function __construct(
        public string $customer_id,
        public string $customer_name,
        public ?string $phone_number = null,
        public ?string $email = null,
        public ?string $national_id = null,
    ) {}
}
