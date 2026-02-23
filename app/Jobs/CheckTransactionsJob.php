<?php

namespace App\Jobs;

use App\Models\BankSession;
use App\Models\Transaction;
use App\Services\BankApiService;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CheckTransactionsJob implements ShouldQueue
{
    use Queueable;

    public function __construct() {}

    public function handle(): void
    {
        $sessions = BankSession::whereNotNull('access_token')
            ->whereNotNull('refresh_token')
            ->get();

        foreach ($sessions as $session) {
            try {
                $this->procesSession($session);
            } catch (\Throwable $e) {
                Log::error("CheckTransactionsJob: failed for session {$session->id}", [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function procesSession(BankSession $session): void
    {
        if (! $session->isAuthenticated() && ! $session->refreshTokenIfNeeded()) {
            return;
        }

        $apiService = new BankApiService($session->bank, $session->device_id, $session->access_token);
        $accounts = $apiService->getAccounts();

        foreach ($accounts as $account) {
            $this->processAccount($session, $apiService, $account->number);
        }
    }

    private function processAccount(BankSession $session, BankApiService $apiService, string $accountNumber): void
    {
        $transactions = $apiService->getTransactions($accountNumber);

        foreach ($transactions as $txData) {
            $isNew = ! Transaction::where('bank_session_id', $session->id)
                ->where('account_number', $accountNumber)
                ->where('reference', $txData->reference)
                ->where('type', $txData->type)
                ->where('event', $txData->event)
                ->exists();

            if (! $isNew) {
                continue;
            }

            $transaction = Transaction::create([
                'bank_session_id' => $session->id,
                'account_number' => $accountNumber,
                'reference' => $txData->reference,
                'code' => $txData->code,
                'code_description' => $txData->code_description,
                'type' => $txData->type,
                'type_label' => $txData->type_label,
                'amount' => $txData->amount,
                'amount_formatted' => $txData->amount_formatted,
                'currency' => $txData->currency,
                'event' => $txData->event,
                'description' => $txData->description,
                'counterparty_name' => $txData->counterparty_name,
                'counterparty_account_number' => $txData->counterparty_account_number,
                'transaction_date' => Carbon::parse($txData->date),
            ]);

            // Only notify for INIT events (not reversals) and recent transactions
            if ($txData->event === 'INIT' && Carbon::parse($txData->date)->isAfter(now()->subHour())) {
                $this->sendNotification($session, $transaction);
            }
        }
    }

    private function sendNotification(BankSession $session, Transaction $transaction): void
    {
        $token = $session->bank->config('telegram_token');

        if (empty($token)) {
            return;
        }

        $icon = $transaction->type === 'credit' ? '📥' : '📤';
        $label = $transaction->type === 'credit' ? 'Received' : 'Sent';
        $counterparty = $transaction->counterparty_name ?? '-';

        $message = "{$icon} *New Transaction*\n\n"
            ."*{$label}:* {$transaction->amount_formatted}\n"
            ."*Type:* {$transaction->code_description}\n"
            ."*Account:* `{$transaction->account_number}`\n"
            ."*Party:* {$counterparty}\n"
            ."*Date:* {$transaction->transaction_date->format('Y-m-d H:i')}\n";

        if ($transaction->description) {
            $message .= "*Details:* {$transaction->description}\n";
        }

        try {
            Http::post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $session->telegram_chat_id,
                'text' => $message,
                'parse_mode' => 'Markdown',
            ]);

            $transaction->update(['notified_at' => now()]);
        } catch (\Throwable $e) {
            Log::error("CheckTransactionsJob: failed to send notification for transaction {$transaction->id}", [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
