<?php

namespace App\Services\Banks\Support;

/**
 * Libyan IBAN layout: LY + 2 check digits + 3 digit bank code + 3 digit branch code + 15 digit account number.
 */
final class LibyanIban
{
    private const COUNTRY = 'LY';

    private const LENGTH = 25;

    public static function make(string $bankCode, string $branchCode, string $accountNumber): string
    {
        $bban = self::digits($bankCode, 3).self::digits($branchCode, 3).self::digits($accountNumber, 15);

        $checkDigits = 98 - self::mod97(self::toNumeric($bban.self::COUNTRY.'00'));

        return self::COUNTRY.str_pad((string) $checkDigits, 2, '0', STR_PAD_LEFT).$bban;
    }

    public static function isValid(string $iban): bool
    {
        $iban = self::normalize($iban);

        if (strlen($iban) !== self::LENGTH || ! str_starts_with($iban, self::COUNTRY) || ! ctype_digit(substr($iban, 2))) {
            return false;
        }

        return self::mod97(self::toNumeric(substr($iban, 4).substr($iban, 0, 4))) === 1;
    }

    public static function bankCode(string $iban): string
    {
        return substr(self::normalize($iban), 4, 3);
    }

    public static function branchCode(string $iban): string
    {
        return substr(self::normalize($iban), 7, 3);
    }

    public static function accountNumber(string $iban): string
    {
        return substr(self::normalize($iban), 10, 15);
    }

    public static function normalize(string $iban): string
    {
        return strtoupper(preg_replace('/\s+/', '', $iban) ?? '');
    }

    private static function digits(string $value, int $length): string
    {
        return str_pad(preg_replace('/\D/', '', $value) ?? '', $length, '0', STR_PAD_LEFT);
    }

    private static function toNumeric(string $value): string
    {
        return preg_replace_callback('/[A-Z]/', fn (array $match) => (string) (ord($match[0]) - 55), $value) ?? '';
    }

    private static function mod97(string $numeric): int
    {
        $remainder = 0;

        foreach (str_split($numeric, 7) as $chunk) {
            $remainder = (int) (($remainder.$chunk) % 97);
        }

        return $remainder;
    }
}
