<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    protected $fillable = [
        'bank_session_id',
        'account_number',
        'reference',
        'code',
        'code_description',
        'type',
        'type_label',
        'amount',
        'amount_formatted',
        'currency',
        'event',
        'description',
        'counterparty_name',
        'counterparty_account_number',
        'transaction_date',
        'notified_at',
    ];

    public function casts(): array
    {
        return [
            'transaction_date' => 'datetime',
            'notified_at' => 'datetime',
        ];
    }

    public function bankSession(): BelongsTo
    {
        return $this->belongsTo(BankSession::class);
    }
}
