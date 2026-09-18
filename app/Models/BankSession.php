<?php

namespace App\Models;

use App\Enums\Bank;
use App\Services\Banks\BankManager;
use App\Services\Banks\Contracts\BankDriver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankSession extends Model
{
    /** @use HasFactory<\Database\Factories\BankSessionFactory> */
    use HasFactory;

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
        'default_account_number',
        'credentials',
        'meta',
    ];

    protected $hidden = [
        'credentials',
    ];

    public function casts(): array
    {
        return [
            'bank' => Bank::class,
            'access_token_expires_at' => 'datetime',
            'refresh_token_expires_at' => 'datetime',
            'credentials' => 'encrypted:array',
            'meta' => 'array',
        ];
    }

    /** @param  Builder<BankSession>  $query */
    public function scopeForChat(Builder $query, int $chatId, Bank $bank): Builder
    {
        return $query->where('telegram_chat_id', $chatId)->where('bank', $bank->value);
    }

    public function driver(): BankDriver
    {
        return app(BankManager::class)->forSession($this);
    }

    public function isAuthenticated(): bool
    {
        return $this->access_token !== null
            && $this->access_token_expires_at !== null
            && $this->access_token_expires_at->isFuture();
    }

    public function clearTokens(): void
    {
        $this->update([
            'access_token' => null,
            'refresh_token' => null,
            'access_token_expires_at' => null,
            'refresh_token_expires_at' => null,
        ]);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function vouchers(): HasMany
    {
        return $this->hasMany(Voucher::class);
    }
}
