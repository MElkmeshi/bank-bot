<?php

namespace App\Data\Bank;

use Spatie\LaravelData\Data;

class CustomerData extends Data
{
    public function __construct(
        public string $name,
        public string $customer_id,
    ) {}
}
