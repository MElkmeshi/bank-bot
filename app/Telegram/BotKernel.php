<?php

namespace App\Telegram;

use App\Enums\Bank;
use App\Services\FirebaseService;
use App\Telegram\Conversations\RegisterConversation;
use App\Telegram\Conversations\TransferConversation;
use App\Telegram\Handlers\BalanceHandler;
use App\Telegram\Handlers\DeleteDeviceHandler;
use App\Telegram\Handlers\TransactionsHandler;
use SergiX44\Nutgram\Nutgram;

class BotKernel
{
    public static function register(Nutgram $bot, Bank $bank): void
    {
        $firebaseService = new FirebaseService;
        $balanceHandler = new BalanceHandler($bank);
        $deleteDeviceHandler = new DeleteDeviceHandler($bank);
        $transactionsHandler = new TransactionsHandler($bank);

        $bot->getContainer()->bind(RegisterConversation::class, fn () => new RegisterConversation($bank, $firebaseService));
        $bot->getContainer()->bind(TransferConversation::class, fn () => new TransferConversation($bank));

        $bot->onCommand('start', function (Nutgram $bot) {
            RegisterConversation::begin($bot);
        });

        $bot->onCommand('balance', $balanceHandler);

        $bot->onCallbackQueryData('balance_view:{data}', function (Nutgram $bot) use ($balanceHandler) {
            $balanceHandler->handleAccountView($bot);
        });

        $bot->onCallbackQueryData('balance_set_default:{data}', function (Nutgram $bot) use ($balanceHandler) {
            $balanceHandler->handleSetDefault($bot);
        });

        $bot->onCommand('transactions', $transactionsHandler);

        $bot->onCallbackQueryData('transactions_view:{data}', function (Nutgram $bot) use ($transactionsHandler) {
            $transactionsHandler->handleAccountView($bot);
        });

        $bot->onCommand('delete_device', $deleteDeviceHandler);

        $bot->onCallbackQueryData('delete_device_confirm:{data}', function (Nutgram $bot) use ($deleteDeviceHandler) {
            $deleteDeviceHandler->handleConfirm($bot);
        });

        $bot->onCallbackQueryData('delete_device_cancel:{data}', function (Nutgram $bot) use ($deleteDeviceHandler) {
            $deleteDeviceHandler->handleCancel($bot);
        });

        $bot->onCommand('transfer', function (Nutgram $bot) {
            TransferConversation::begin($bot);
        });

        $bot->fallback(function (Nutgram $bot) {
            $bot->sendMessage(
                "I don't understand that. Available commands:\n"
                ."/start - Register or login\n"
                ."/balance - Check your account balance\n"
                ."/transactions - View recent transactions\n"
                ."/transfer - Send a bank transfer\n"
                .'/delete_device - Remove this device'
            );
        });
    }
}
