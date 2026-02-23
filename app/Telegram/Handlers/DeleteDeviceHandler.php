<?php

namespace App\Telegram\Handlers;

use App\Enums\Bank;
use App\Models\BankSession;
use App\Services\BankApiService;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class DeleteDeviceHandler
{
    public function __construct(
        private readonly Bank $bank,
    ) {}

    public function __invoke(Nutgram $bot): void
    {
        $session = BankSession::where('telegram_chat_id', $bot->chatId())
            ->where('bank', $this->bank->value)
            ->first();

        if (! $session || ! $session->isAuthenticated()) {
            $bot->sendMessage('You are not authenticated. Please use /start to register.');

            return;
        }

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make('✅ Yes, delete device', callback_data: "delete_device_confirm:{$this->bank->value}"),
                InlineKeyboardButton::make('❌ No, cancel', callback_data: "delete_device_cancel:{$this->bank->value}"),
            );

        $bot->sendMessage(
            '⚠️ Are you sure you want to delete this device? You will need to re-register to use the bot again.',
            reply_markup: $keyboard
        );
    }

    public function handleConfirm(Nutgram $bot): void
    {
        $bot->answerCallbackQuery();

        $session = BankSession::where('telegram_chat_id', $bot->chatId())
            ->where('bank', $this->bank->value)
            ->first();

        if (! $session || (! $session->isAuthenticated() && ! $session->refreshTokenIfNeeded())) {
            $bot->sendMessage('Session not found. Please use /start to register.');

            return;
        }

        try {
            $apiService = new BankApiService($this->bank, $session->device_id, $session->access_token);
            $apiService->deleteDevice($session->customer_id);

            $session->update([
                'access_token' => null,
                'refresh_token' => null,
                'access_token_expires_at' => null,
                'refresh_token_expires_at' => null,
            ]);

            $bot->sendMessage('✅ Device deleted successfully. Use /start to register again.');
        } catch (\Throwable $e) {
            $bot->sendMessage("Failed to delete device: {$e->getMessage()}");
        }
    }

    public function handleCancel(Nutgram $bot): void
    {
        $bot->answerCallbackQuery();
        $bot->sendMessage('Device deletion cancelled.');
    }
}
