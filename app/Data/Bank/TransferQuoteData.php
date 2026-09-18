<?php

namespace App\Data\Bank;

use Spatie\LaravelData\Data;

/**
 * A transfer that has been prepared by the bank and is waiting for confirmation.
 *
 * @property array<string, mixed> $meta Driver-specific state needed to confirm the transfer.
 */
class TransferQuoteData extends Data
{
    /** @param  array<string, mixed>  $meta */
    public function __construct(
        public string $amount_formatted,
        public string $total_amount_formatted,
        public string $currency,
        public string $creditor_identification,
        public bool $requires_otp,
        public ?string $reference = null,
        public ?string $fees_formatted = null,
        public ?string $description = null,
        public ?string $debtor_name = null,
        public ?string $creditor_name = null,
        public array $meta = [],
    ) {}
}
