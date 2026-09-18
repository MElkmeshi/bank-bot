<?php

namespace App\Data\Bank;

use Spatie\LaravelData\Data;

/**
 * A voucher purchase the bank has prepared and is waiting for confirmation.
 *
 * @property array<string, mixed> $meta Driver-specific state needed to confirm the purchase.
 */
class VoucherQuoteData extends Data
{
    /** @param  array<string, mixed>  $meta */
    public function __construct(
        public string $account_number,
        public string $provider_id,
        public string $provider_name,
        public string $amount,
        public string $amount_formatted,
        public string $currency,
        public bool $requires_otp,
        public array $meta = [],
    ) {}
}
