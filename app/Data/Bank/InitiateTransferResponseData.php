<?php

namespace App\Data\Bank;

use Spatie\LaravelData\Data;

class InitiateTransferResponseData extends Data
{
    /**
     * @param  array{name: string, account_number: string}  $debtor
     * @param  array{name: string, identification: string}  $creditor
     * @param  array{code: string, description: string}  $status
     */
    public function __construct(
        public string $transaction_id,
        public string $original_amount_formatted,
        public string $total_amount_formatted,
        public string $fees_formatted,
        public string $currency,
        public ?string $description,
        public bool $requires_otp,
        public ?string $verification_reference,
        public array $debtor,
        public array $creditor,
        public array $status,
    ) {}
}
