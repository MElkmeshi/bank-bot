<?php

namespace App\Models;

use App\Enums\Bank;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One HTTP exchange with a bank's API, kept for debugging and auditing.
 *
 * Bodies are stored encrypted; secret headers and credential fields are
 * redacted before storage by App\Services\Banks\Support\BankRequestRecorder.
 */
class BankRequest extends Model
{
    use Prunable;

    public const UPDATED_AT = null;

    protected $fillable = [
        'bank',
        'bank_session_id',
        'method',
        'url',
        'request_headers',
        'request_body',
        'status',
        'response_headers',
        'response_body',
        'error',
        'duration_ms',
        'created_at',
    ];

    public function casts(): array
    {
        return [
            'bank' => Bank::class,
            'request_headers' => 'array',
            'response_headers' => 'array',
            'request_body' => 'encrypted',
            'response_body' => 'encrypted',
            'created_at' => 'datetime',
        ];
    }

    public function bankSession(): BelongsTo
    {
        return $this->belongsTo(BankSession::class);
    }

    public function isSuccessful(): bool
    {
        return $this->error === null && $this->status !== null && $this->status < 400;
    }

    /** @return Builder<BankRequest> */
    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays((int) config('banks.request_log.retention_days', 30)));
    }
}
