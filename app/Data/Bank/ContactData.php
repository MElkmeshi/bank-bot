<?php

namespace App\Data\Bank;

use Spatie\LaravelData\Data;

class ContactData extends Data
{
    public const SCHEMA_IBAN = 'iban';

    public const SCHEMA_ACCOUNT = 'account';

    public function __construct(
        public string $name,
        public string $identification,
        public string $schema = self::SCHEMA_IBAN,
        public ?string $institution_code = null,
        public ?string $institution_name = null,
    ) {}
}
