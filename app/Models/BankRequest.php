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
 * Headers and bodies are stored encrypted; credential fields in request
 * bodies are masked by App\Services\Banks\Support\BankRequestRecorder.
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
            'request_headers' => 'encrypted:array',
            'response_headers' => 'encrypted:array',
            'request_body' => 'encrypted',
            'response_body' => 'encrypted',
            'created_at' => 'datetime',
        ];
    }

    public function bankSession(): BelongsTo
    {
        return $this->belongsTo(BankSession::class);
    }

    /**
     * A curl command that replays this request as it was sent.
     */
    public function toCurl(): string
    {
        $parts = ['curl -sk -X '.$this->method.' '.$this->shellQuote($this->url)];

        foreach ($this->request_headers ?? [] as $name => $value) {
            if (in_array(strtolower($name), ['host', 'content-length'], true)) {
                continue;
            }

            $parts[] = '-H '.$this->shellQuote("{$name}: {$value}");
        }

        if ($this->request_body !== null && $this->request_body !== '') {
            $parts[] = '--data-binary '.$this->shellQuote($this->request_body);
        }

        return implode(" \\\n  ", $parts);
    }

    public function isSuccessful(): bool
    {
        return $this->error === null && $this->status !== null && $this->status < 400;
    }

    private function shellQuote(string $value): string
    {
        return "'".str_replace("'", "'\\''", $value)."'";
    }

    /** @return Builder<BankRequest> */
    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays((int) config('banks.request_log.retention_days', 30)));
    }
}
