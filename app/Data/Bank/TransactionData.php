<?php

namespace App\Data\Bank;

use Spatie\LaravelData\Data;

class TransactionData extends Data
{
    public const TYPE_CREDIT = 'credit';

    public const TYPE_DEBIT = 'debit';

    public function __construct(
        public string $reference,
        public string $type,
        public string $date,
        public string $amount,
        public string $amount_formatted,
        public string $currency,
        public string $code = '',
        public string $code_description = '',
        public string $type_label = '',
        public string $event = 'INIT',
        public ?string $currency_symbols = null,
        public ?string $description = null,
        public ?string $counterparty_name = null,
        public ?string $counterparty_account_number = null,
    ) {}

    public function isCredit(): bool
    {
        return $this->type === self::TYPE_CREDIT;
    }
}
