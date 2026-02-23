<?php

namespace App\Data\Bank;

use Spatie\LaravelData\Data;

class RefreshTokenResponseData extends Data
{
    public function __construct(
        public string $access_token,
        public int $access_token_expires_at,
    ) {}
}
