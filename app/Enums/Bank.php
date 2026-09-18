<?php

namespace App\Enums;

enum Bank: string
{
    case Andalus = 'andalus';
    case Nuran = 'nuran';
    case Jumhouria = 'jumhouria';
    case Nab = 'nab';

    public function config(string $key, mixed $default = null): mixed
    {
        return config("banks.banks.{$this->value}.{$key}", $default);
    }

    public function driver(): string
    {
        return $this->config('driver');
    }

    public function displayName(): string
    {
        return $this->config('name');
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
