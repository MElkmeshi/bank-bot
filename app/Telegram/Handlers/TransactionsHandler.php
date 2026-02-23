<?php

namespace App\Telegram\Handlers;

use App\Enums\Bank;
use App\Models\BankSession;
use App\Services\BankApiService;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class TransactionsHandler
{
    public function __construct(
        private readonly Bank $bank,
    ) {}

    public function __invoke(Nutgram $bot): void
    {
        $session = BankSession::where('telegram_chat_id', $bot->chatId())
            ->where('bank', $this->bank->value)
            ->first();

        if (! $session || (! $session->isAuthenticated() && ! $session->refreshTokenIfNeeded())) {
            $bot->sendMessage('You are not authenticated. Please use /start to register.');

            return;
        }

        try {
            $apiService = new BankApiService($this->bank, $session->device_id, $session->access_token);
            $accounts = $apiService->getAccounts();

            if ($accounts->count() === 0) {
                $bot->sendMessage('No accounts found.');

                return;
            }

            if ($accounts->count() === 1 && $session->default_account_number === null) {
                $session->update(['default_account_number' => $accounts->first()->number]);
                $session->refresh();
            }

            if ($accounts->count() === 1 || $session->default_account_number !== null) {
                $accountNumber = $session->default_account_number ?? $accounts->first()->number;
                $this->sendTransactions($bot, $apiService, $accountNumber);

                return;
            }

            $keyboard = InlineKeyboardMarkup::make();

            foreach ($accounts as $account) {
                $keyboard->addRow(
                    InlineKeyboardButton::make(
                        "📋 {$account->number}",
                        callback_data: "transactions_view:{$this->bank->value}:{$account->number}"
                    )
                );
            }

            $bot->sendMessage(
                'Choose an account to view transactions:',
                reply_markup: $keyboard
            );
        } catch (\Throwable $e) {
            $bot->sendMessage("Failed to fetch transactions: {$e->getMessage()}");
        }
    }

    public function handleAccountView(Nutgram $bot): void
    {
        $bot->answerCallbackQuery();

        $callbackData = $bot->callbackQuery()->data;
        [, , $accountNumber] = explode(':', $callbackData, 3);

        $session = BankSession::where('telegram_chat_id', $bot->chatId())
            ->where('bank', $this->bank->value)
            ->first();

        if (! $session || (! $session->isAuthenticated() && ! $session->refreshTokenIfNeeded())) {
            $bot->sendMessage('Session expired. Please use /start to register.');

            return;
        }

        try {
            $apiService = new BankApiService($this->bank, $session->device_id, $session->access_token);
            $this->sendTransactions($bot, $apiService, $accountNumber);
        } catch (\Throwable $e) {
            $bot->sendMessage("Failed to fetch transactions: {$e->getMessage()}");
        }
    }

    private function sendTransactions(Nutgram $bot, BankApiService $apiService, string $accountNumber): void
    {
        $transactions = $apiService->getTransactions($accountNumber);

        if ($transactions->count() === 0) {
            $bot->sendMessage("No transactions found for account `{$accountNumber}`.", parse_mode: 'Markdown');

            return;
        }

        $lines = ["📊 *Recent Transactions*\nAccount: `{$accountNumber}`\n"];

        foreach ($transactions->toCollection()->take(10) as $tx) {
            $icon = $tx->type === 'credit' ? '📥' : '📤';
            $lines[] = "{$icon} *{$tx->amount_formatted}*"
                .($tx->counterparty_name !== null ? " — {$tx->counterparty_name}" : '')
                ."\n_{$tx->code_description}_"
                .($tx->description !== null ? " · {$tx->description}" : '')
                ."\n📅 {$tx->date}";
        }

        $bot->sendMessage(implode("\n\n", $lines), parse_mode: 'Markdown');
    }
}
