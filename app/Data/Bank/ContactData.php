<?php

namespace App\Data\Bank;

use Spatie\LaravelData\Data;

class ContactData extends Data
{
    public function __construct(
        public int $id,
        public string $uuid,
        public string $name,
        public string $schema,
        public string $identification,
        public ?string $institution_code,
        public ?string $institution_name,
        public string $type,
        public string $type_label,
    ) {}
}
