<?php

namespace App\Telegram\Handlers;

use App\Enums\Bank;
use App\Telegram\Concerns\ResolvesBankSession;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class DeleteDeviceHandler
{
    use ResolvesBankSession;

    public function __construct(
        private readonly Bank $bank,
    ) {}

    public function __invoke(Nutgram $bot): void
    {
        $session = $this->findSession($bot);

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

        $driver = $this->authenticatedDriver($bot, 'Session not found. Please use /start to register.');

        if (! $driver) {
            return;
        }

        try {
            $driver->logout();

            $driver->session()->clearTokens();
            $driver->session()->update(['credentials' => null]);

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
