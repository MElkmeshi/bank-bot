<?php

namespace App\Telegram;

use App\Enums\Bank;
use App\Services\FirebaseService;
use App\Telegram\Conversations\RegisterConversation;
use App\Telegram\Handlers\BalanceHandler;
use App\Telegram\Handlers\DeleteDeviceHandler;
use SergiX44\Nutgram\Nutgram;

class BotKernel
{
    public static function register(Nutgram $bot, Bank $bank): void
    {
        $firebaseService = new FirebaseService;
        $balanceHandler = new BalanceHandler($bank);
        $deleteDeviceHandler = new DeleteDeviceHandler($bank);

        $bot->getContainer()->bind(RegisterConversation::class, fn () => new RegisterConversation($bank, $firebaseService));

        $bot->onCommand('start', function (Nutgram $bot) {
            RegisterConversation::begin($bot);
        });

        $bot->onCommand('balance', $balanceHandler);

        $bot->onCallbackQueryData('balance_view:*', function (Nutgram $bot) use ($balanceHandler) {
            $balanceHandler->handleAccountView($bot);
        });

        $bot->onCallbackQueryData('balance_set_default:*', function (Nutgram $bot) use ($balanceHandler) {
            $balanceHandler->handleSetDefault($bot);
        });

        $bot->onCommand('delete_device', $deleteDeviceHandler);

        $bot->onCallbackQueryData('delete_device_confirm:*', function (Nutgram $bot) use ($deleteDeviceHandler) {
            $deleteDeviceHandler->handleConfirm($bot);
        });

        $bot->onCallbackQueryData('delete_device_cancel:*', function (Nutgram $bot) use ($deleteDeviceHandler) {
            $deleteDeviceHandler->handleCancel($bot);
        });

        $bot->fallback(function (Nutgram $bot) {
            $bot->sendMessage(
                "I don't understand that. Available commands:\n"
                ."/start - Register or login\n"
                ."/balance - Check your account balance\n"
                .'/delete_device - Remove this device'
            );
        });
    }
}
