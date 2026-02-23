<?php

namespace App\Data\Bank;

use Spatie\LaravelData\Data;

class AccountData extends Data
{
    public function __construct(
        public string $number,
        public string $description,
        public string $available_balance,
        public string $available_balance_formatted,
        public string $currency,
        public string $currency_symbols,
        public ?string $iban = null,
    ) {}
}
