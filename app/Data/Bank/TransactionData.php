<?php

namespace App\Data\Bank;

use Spatie\LaravelData\Data;

class TransactionData extends Data
{
    public function __construct(
        public string $reference,
        public string $code,
        public string $code_description,
        public string $type,
        public string $type_label,
        public string $date,
        public string $amount,
        public string $amount_formatted,
        public string $currency,
        public string $currency_symbols,
        public string $event,
        public ?string $description = null,
        public ?string $counterparty_name = null,
        public ?string $counterparty_account_number = null,
    ) {}
}
