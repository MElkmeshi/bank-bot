<?php

namespace App\Data\Bank;

use Spatie\LaravelData\Data;

/**
 * A mobile operator / service whose prepaid vouchers the bank sells.
 */
class VoucherProviderData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
    ) {}
}
