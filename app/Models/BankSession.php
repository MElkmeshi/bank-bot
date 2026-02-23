<?php

namespace App\Models;

use App\Enums\Bank;
use App\Services\BankApiService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'default_account_number',
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

    public function needsTokenRefresh(): bool
    {
        return $this->access_token !== null
            && $this->access_token_expires_at !== null
            && ! $this->access_token_expires_at->isFuture();
    }

    /**
     * Refresh the access token if expired. Returns false if refresh fails or no refresh token exists.
     */
    public function refreshTokenIfNeeded(): bool
    {
        if (! $this->needsTokenRefresh()) {
            return true;
        }

        if (empty($this->refresh_token)) {
            return false;
        }

        try {
            $apiService = new BankApiService($this->bank, $this->device_id);
            $refreshed = $apiService->refreshToken($this->refresh_token);

            $this->update([
                'access_token' => $refreshed->access_token,
                'access_token_expires_at' => Carbon::createFromTimestamp($refreshed->access_token_expires_at),
            ]);

            $this->refresh();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}
