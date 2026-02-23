<?php

namespace App\Models;

use App\Enums\Bank;
use Illuminate\Database\Eloquent\Model;

class BankSession extends Model
{
    protected $fillable = [
        'telegram_chat_id',
        'bank',
        'customer_id',
        'device_id',
        'access_token',
        'refresh_token',
        'access_token_expires_at',
        'refresh_token_expires_at',
        'verification_reference',
    ];

    public function casts(): array
    {
        return [
            'bank' => Bank::class,
            'access_token_expires_at' => 'datetime',
            'refresh_token_expires_at' => 'datetime',
        ];
    }

    public function isAuthenticated(): bool
    {
        return $this->access_token !== null
            && $this->access_token_expires_at !== null
            && $this->access_token_expires_at->isFuture();
    }
}
