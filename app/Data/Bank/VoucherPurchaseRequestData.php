<?php

namespace App\Data\Bank;

use Spatie\LaravelData\Data;

class VoucherPurchaseRequestData extends Data
{
    public function __construct(
        public string $account_number,
        public string $provider_id,
        public string $denomination_id,
    ) {}
}
