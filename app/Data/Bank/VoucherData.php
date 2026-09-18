<?php

namespace App\Data\Bank;

use Spatie\LaravelData\Data;

/**
 * A purchased prepaid voucher: the secret code is what the user types into their phone.
 */
class VoucherData extends Data
{
    public function __construct(
        public string $provider_name,
        public string $amount,
        public string $currency,
        public string $code,
        public ?string $serial = null,
        public ?string $reference = null,
        public ?string $purchased_at = null,
    ) {}
}
