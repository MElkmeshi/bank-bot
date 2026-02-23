<?php

namespace App\Data\Bank;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

class ProfileResponseData extends Data
{
    public function __construct(
        /** @var AccountData[] */
        #[DataCollectionOf(AccountData::class)]
        public DataCollection $accounts,
    ) {}
}
