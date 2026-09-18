<?php

namespace App\Exceptions;

use App\Enums\Bank;
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

    /**
     * Build a human readable description of a failed bank response, keeping
     * every detail the bank returned: message, field errors and error code.
     */
    public static function describe(Bank $bank, int $status, string $message, mixed $payload): string
    {
        $lines = ["{$bank->displayName()} responded with HTTP {$status}: {$message}"];

        $errors = is_array($payload) ? ($payload['errors'] ?? $payload['messages'] ?? []) : [];

        foreach ((array) $errors as $field => $fieldErrors) {
            foreach ((array) $fieldErrors as $error) {
                if (is_string($error) && $error !== '' && $error !== $message) {
                    $lines[] = is_string($field) ? "• {$field}: {$error}" : "• {$error}";
                }
            }
        }

        $code = is_array($payload) ? ($payload['code'] ?? $payload['errorCode'] ?? null) : null;

        if (is_scalar($code) && (string) $code !== '') {
            $lines[] = "(code: {$code})";
        }

        return implode("\n", $lines);
    }
}
