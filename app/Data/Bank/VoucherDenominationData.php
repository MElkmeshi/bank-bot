<?php

namespace App\Data\Bank;

use Spatie\LaravelData\Data;

class VoucherDenominationData extends Data
{
    public function __construct(
        public string $id,
        public string $amount,
        public string $currency,
        public string $label,
    ) {}
}
