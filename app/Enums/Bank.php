<?php

namespace App\Enums;

enum Bank: string
{
    case Andalus = 'andalus';
    case Nuran = 'nuran';

    public function config(string $key): mixed
    {
        return config("banks.{$this->value}.{$key}");
    }

    public function displayName(): string
    {
        return $this->config('name');
    }
}
