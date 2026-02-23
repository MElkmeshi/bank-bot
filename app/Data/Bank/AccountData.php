<?php

namespace App\Data\Bank;

use Spatie\LaravelData\Data;

class AccountData extends Data
{
    public function __construct(
        public string $account_number,
        public string $balance,
        public string $currency,
    ) {}
}
