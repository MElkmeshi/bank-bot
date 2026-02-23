<?php

namespace App\Data\Firebase;

use Spatie\LaravelData\Data;

class FirebaseInstallationResponseData extends Data
{
    public function __construct(
        public string $fid,
        public string $authToken,
        public string $expiresIn,
    ) {}
}
