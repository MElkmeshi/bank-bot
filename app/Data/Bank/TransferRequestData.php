<?php

namespace App\Data\Bank;

use Spatie\LaravelData\Data;

class TransferRequestData extends Data
{
    public function __construct(
        public string $debtor_account_number,
        public string $iban,
        public string $amount,
        public string $currency = 'LYD',
        public ?string $description = null,
    ) {}
}
