<?php

namespace App\Console\Commands;

use App\Models\BankSession;
use App\Models\Transaction;
use App\Services\BankApiService;
use Illuminate\Console\Command;

class ListTransactionsCommand extends Command
{
    protected $signature = 'transactions:list {--session= : Filter by bank session ID} {--account= : Filter by account number} {--limit=50 : Number of transactions to show} {--dump : Fetch and dump raw JSON from the bank API (requires --session and --account)}';

    protected $description = 'List stored transactions for debugging';

    public function handle(): void
    {
        if ($this->option('dump')) {
            $this->dumpRaw();

            return;
        }

        $query = Transaction::query()->with('bankSession')->latest('transaction_date');

        if ($sessionId = $this->option('session')) {
            $query->where('bank_session_id', $sessionId);
        }

        if ($account = $this->option('account')) {
            $query->where('account_number', $account);
        }

        $transactions = $query->limit($this->option('limit'))->get();

        if ($transactions->isEmpty()) {
            $this->warn('No transactions found.');

            return;
        }

        $this->info("Showing {$transactions->count()} transaction(s):\n");

        $this->table(
            ['ID', 'Session', 'Account', 'Reference', 'Type', 'Event', 'Amount', 'Counterparty', 'Date', 'Notified'],
            $transactions->map(fn (Transaction $tx) => [
                $tx->id,
                $tx->bank_session_id,
                $tx->account_number,
                $tx->reference,
                $tx->type,
                $tx->event,
                $tx->amount_formatted,
                $tx->counterparty_name ?? '-',
                $tx->transaction_date->format('Y-m-d H:i'),
                $tx->notified_at ? '✅' : '❌',
            ])
        );

        $this->line('');
        $this->info('Sessions in DB:');
        BankSession::all()->each(function (BankSession $session) {
            $this->line("  [{$session->id}] bank={$session->bank->value} chat_id={$session->telegram_chat_id} authenticated=".($session->isAuthenticated() ? 'yes' : 'no'));
        });
    }

    private function dumpRaw(): void
    {
        $sessionId = $this->option('session');
        $account = $this->option('account');

        if (! $sessionId || ! $account) {
            $this->error('--dump requires both --session and --account.');

            return;
        }

        $session = BankSession::find($sessionId);

        if (! $session) {
            $this->error("Session {$sessionId} not found.");

            return;
        }

        if (! $session->isAuthenticated() && ! $session->refreshTokenIfNeeded()) {
            $this->error("Session {$sessionId} is not authenticated.");

            return;
        }

        $apiService = new BankApiService($session->bank, $session->device_id, $session->access_token);
        $raw = $apiService->getRawTransactions($account);

        $this->line(json_encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
