<?php

namespace App\Data\Firebase;

use Spatie\LaravelData\Data;

class FirebaseInstallationData extends Data
{
    public function __construct(
        public string $appId,
        public string $authVersion,
        public string $sdkVersion,
    ) {}
}
