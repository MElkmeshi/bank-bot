<?php

namespace App\Data\Bank;

use Spatie\LaravelData\Data;

class TransferReceiptData extends Data
{
    public function __construct(
        public ?string $reference = null,
        public ?string $message = null,
    ) {}
}
