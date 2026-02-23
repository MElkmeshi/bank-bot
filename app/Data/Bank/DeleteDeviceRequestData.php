<?php

namespace App\Data\Bank;

use Spatie\LaravelData\Data;

class DeleteDeviceRequestData extends Data
{
    public function __construct(
        public string $customer_id,
        public string $device_id,
    ) {}
}
