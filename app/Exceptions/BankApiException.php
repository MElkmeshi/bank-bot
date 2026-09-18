<?php

namespace App\Exceptions;

use RuntimeException;

class BankApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $statusCode = null,
        public readonly mixed $payload = null,
    ) {
        parent::__construct($message);
    }
}
