<?php

namespace App\Data\Bank;

use Spatie\LaravelData\Data;

/**
 * Labels shown to the user when asking for the two login credentials of a bank.
 */
class CredentialPromptsData extends Data
{
    public function __construct(
        public string $identifier_label,
        public string $secret_label,
        public ?string $identifier_hint = null,
    ) {}
}
