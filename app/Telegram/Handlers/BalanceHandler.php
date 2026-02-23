<?php

namespace App\Telegram\Handlers;

use App\Enums\Bank;
use App\Models\BankSession;
use App\Services\BankApiService;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class BalanceHandler
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

            if ($accounts->count() === 1 || $session->default_account_number !== null) {
                $account = $session->default_account_number !== null
                    ? $accounts->toCollection()->firstWhere('number', $session->default_account_number) ?? $accounts->first()
                    : $accounts->first();

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
            $bot->sendMessage("Failed to fetch balance: {$e->getMessage()}");
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
            $accounts = $apiService->getAccounts();

            $account = $accounts->toCollection()->firstWhere('number', $accountNumber);

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

            $isDefault = $session->default_account_number === $accountNumber;
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
            $bot->sendMessage("Failed to fetch balance: {$e->getMessage()}");
        }
    }

    public function handleSetDefault(Nutgram $bot): void
    {
        $bot->answerCallbackQuery(text: '⭐ Default account updated!');

        $callbackData = $bot->callbackQuery()->data;
        [, , $accountNumber] = explode(':', $callbackData, 3);

        BankSession::where('telegram_chat_id', $bot->chatId())
            ->where('bank', $this->bank->value)
            ->update(['default_account_number' => $accountNumber]);

        $bot->sendMessage("⭐ Account `{$accountNumber}` set as your default. Use /balance to check it quickly.", parse_mode: 'Markdown');
    }
}
