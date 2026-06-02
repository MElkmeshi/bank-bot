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

        Log::info('CheckTransactionsJob: started', ['session_count' => $sessions->count()]);

        foreach ($sessions as $session) {
            try {
                $this->procesSession($session);
            } catch (\Throwable $e) {
                Log::error("CheckTransactionsJob: failed for session {$session->id}", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('CheckTransactionsJob: finished');
    }

    private function procesSession(BankSession $session): void
    {
        Log::info("CheckTransactionsJob: processing session {$session->id}", [
            'bank' => $session->bank->value,
            'chat_id' => $session->telegram_chat_id,
        ]);

        if (! $session->isAuthenticated() && ! $session->refreshTokenIfNeeded()) {
            Log::warning("CheckTransactionsJob: session {$session->id} is not authenticated and could not refresh token, skipping");

            return;
        }

        $apiService = new BankApiService($session->bank, $session->device_id, $session->access_token);
        $accounts = $apiService->getAccounts();

        Log::info("CheckTransactionsJob: session {$session->id} has {$accounts->count()} account(s)");

        foreach ($accounts as $account) {
            $this->processAccount($session, $apiService, $account->number);
        }
    }

    private function processAccount(BankSession $session, BankApiService $apiService, string $accountNumber): void
    {
        Log::info("CheckTransactionsJob: checking account {$accountNumber} for session {$session->id}");

        $transactions = $apiService->getTransactions($accountNumber);

        Log::info("CheckTransactionsJob: fetched {$transactions->count()} transaction(s) for account {$accountNumber}");

        foreach ($transactions as $txData) {
            $isNew = ! Transaction::where('bank_session_id', $session->id)
                ->where('account_number', $accountNumber)
                ->where('reference', $txData->reference)
                ->where('type', $txData->type)
                ->where('event', $txData->event)
                ->exists();

            if (! $isNew) {
                Log::debug("CheckTransactionsJob: transaction {$txData->reference} already exists, skipping");

                continue;
            }

            Log::info('CheckTransactionsJob: new transaction found', [
                'reference' => $txData->reference,
                'type' => $txData->type,
                'event' => $txData->event,
                'amount' => $txData->amount_formatted,
            ]);

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

            // Notify for all events except reversals, and only for recent transactions
            if (Carbon::parse($txData->date)->isAfter(now()->subHour())) {
                Log::info("CheckTransactionsJob: sending notification for transaction {$transaction->id}");
                $this->sendNotification($session, $transaction);
            } else {
                Log::info("CheckTransactionsJob: skipping notification for transaction {$transaction->id}", [
                    'event' => $txData->event,
                    'date' => $txData->date,
                ]);
            }
        }
    }

    private function sendNotification(BankSession $session, Transaction $transaction): void
    {
        $token = $session->bank->config('telegram_token');

        if (empty($token)) {
            Log::warning("CheckTransactionsJob: no telegram token configured for bank {$session->bank->value}, skipping notification");

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
            Log::info("CheckTransactionsJob: notification sent for transaction {$transaction->id}");
        } catch (\Throwable $e) {
            Log::error("CheckTransactionsJob: failed to send notification for transaction {$transaction->id}", [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
