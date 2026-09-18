<?php

namespace App\Telegram\Handlers;

use App\Enums\Bank;
use App\Models\BankSession;
use App\Telegram\Concerns\ResolvesBankSession;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class BalanceHandler
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
                $account = $accounts->toCollection()->firstWhere('number', $session->default_account_number) ?? $accounts->first();

                $bot->sendMessage(
                    "💰 *Account Balance*\n\n"
                    ."📋 Account: `{$account->number}`\n"
                    ."💵 Balance: *{$account->available_balance_formatted}*",
                    parse_mode: 'Markdown'
                );

                return;
            }

            $keyboard = InlineKeyboardMarkup::make();

            foreach ($accounts as $account) {
                $keyboard->addRow(
                    InlineKeyboardButton::make(
                        "📋 {$account->number}",
                        callback_data: "balance_view:{$this->bank->value}:{$account->number}"
                    )
                );
            }

            $bot->sendMessage(
                'You have multiple accounts. Choose one to view the balance:',
                reply_markup: $keyboard
            );
        } catch (\Throwable $e) {
            $this->sendError($bot, 'Failed to fetch balance', $e);
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
            $account = $driver->accounts()->toCollection()->firstWhere('number', $accountNumber);

            if (! $account) {
                $bot->sendMessage('Account not found.');

                return;
            }

            $setDefaultButton = InlineKeyboardMarkup::make()
                ->addRow(
                    InlineKeyboardButton::make(
                        '⭐ Set as default account',
                        callback_data: "balance_set_default:{$this->bank->value}:{$accountNumber}"
                    )
                );

            $isDefault = $driver->session()->default_account_number === $accountNumber;
            $defaultLabel = $isDefault ? "\n⭐ _This is your default account_" : '';

            $bot->sendMessage(
                "💰 *Account Balance*\n\n"
                ."📋 Account: `{$account->number}`\n"
                ."💵 Balance: *{$account->available_balance_formatted}*"
                .$defaultLabel,
                parse_mode: 'Markdown',
                reply_markup: $isDefault ? null : $setDefaultButton
            );
        } catch (\Throwable $e) {
            $this->sendError($bot, 'Failed to fetch balance', $e);
        }
    }

    public function handleSetDefault(Nutgram $bot): void
    {
        $bot->answerCallbackQuery(text: '⭐ Default account updated!');

        [, , $accountNumber] = explode(':', $bot->callbackQuery()->data, 3);

        BankSession::forChat($bot->chatId(), $this->bank)->update(['default_account_number' => $accountNumber]);

        $bot->sendMessage("⭐ Account `{$accountNumber}` set as your default. Use /balance to check it quickly.", parse_mode: 'Markdown');
    }
}
