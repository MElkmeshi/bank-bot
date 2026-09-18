<?php

namespace App\Models;

use App\Data\Bank\VoucherData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Voucher extends Model
{
    protected $fillable = [
        'bank_session_id',
        'account_number',
        'provider_name',
        'amount',
        'currency',
        'code',
        'serial',
        'reference',
        'purchased_at',
    ];

    public function casts(): array
    {
        return [
            'code' => 'encrypted',
            'purchased_at' => 'datetime',
        ];
    }

    public static function record(BankSession $session, string $accountNumber, VoucherData $voucher): self
    {
        return self::create([
            'bank_session_id' => $session->id,
            'account_number' => $accountNumber,
            'provider_name' => $voucher->provider_name,
            'amount' => $voucher->amount,
            'currency' => $voucher->currency,
            'code' => $voucher->code,
            'serial' => $voucher->serial,
            'reference' => $voucher->reference,
            'purchased_at' => $voucher->purchased_at ?? now(),
        ]);
    }

    public function bankSession(): BelongsTo
    {
        return $this->belongsTo(BankSession::class);
    }
}
