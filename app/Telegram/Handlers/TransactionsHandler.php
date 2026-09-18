<?php

namespace App\Telegram\Handlers;

use App\Enums\Bank;
use App\Services\Banks\Contracts\BankDriver;
use App\Telegram\Concerns\ResolvesBankSession;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class TransactionsHandler
{
    use ResolvesBankSession;

    public function __construct(
        private readonly Bank $bank,
    ) {}

    public function __invoke(Nutgram $bot): void
    {
        $driver = $this->authenticatedDriver($bot);

        if (! $driver) {
            return;
        }

        $session = $driver->session();

        try {
            $accounts = $driver->accounts();

            if ($accounts->count() === 0) {
                $bot->sendMessage('No accounts found.');

                return;
            }

            if ($accounts->count() === 1 && $session->default_account_number === null) {
                $session->update(['default_account_number' => $accounts->first()->number]);
            }

            if ($accounts->count() === 1 || $session->default_account_number !== null) {
                $this->sendTransactions($bot, $driver, $session->default_account_number ?? $accounts->first()->number);

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
            $this->sendError($bot, 'Failed to fetch transactions', $e);
        }
    }

    public function handleAccountView(Nutgram $bot): void
    {
        $bot->answerCallbackQuery();

        [, , $accountNumber] = explode(':', $bot->callbackQuery()->data, 3);

        $driver = $this->authenticatedDriver($bot, 'Session expired. Please use /start to register.');

        if (! $driver) {
            return;
        }

        try {
            $this->sendTransactions($bot, $driver, $accountNumber);
        } catch (\Throwable $e) {
            $this->sendError($bot, 'Failed to fetch transactions', $e);
        }
    }

    private function sendTransactions(Nutgram $bot, BankDriver $driver, string $accountNumber): void
    {
        $transactions = $driver->transactions($accountNumber);

        if ($transactions->count() === 0) {
            $bot->sendMessage("No transactions found for account `{$accountNumber}`.", parse_mode: 'Markdown');

            return;
        }

        $lines = ["📊 *Recent Transactions*\nAccount: `{$accountNumber}`\n"];

        foreach ($transactions->toCollection()->take(10) as $tx) {
            $icon = $tx->isCredit() ? '📥' : '📤';
            $lines[] = "{$icon} *{$tx->amount_formatted}*"
                .($tx->counterparty_name !== null ? " — {$tx->counterparty_name}" : '')
                ."\n_{$tx->code_description}_"
                .($tx->description !== null && $tx->description !== $tx->code_description ? " · {$tx->description}" : '')
                ."\n📅 {$tx->date}";
        }

        $bot->sendMessage(implode("\n\n", $lines), parse_mode: 'Markdown');
    }
}
