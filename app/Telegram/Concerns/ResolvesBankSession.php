<?php

namespace App\Telegram\Concerns;

use App\Enums\Bank;
use App\Models\BankSession;
use App\Services\Banks\Contracts\BankDriver;
use SergiX44\Nutgram\Nutgram;

/**
 * Shared lookup of the chat's BankSession for the handler's bank.
 *
 * @property Bank $bank
 */
trait ResolvesBankSession
{
    protected function findSession(Nutgram $bot): ?BankSession
    {
        return BankSession::forChat($bot->chatId(), $this->bank)->first();
    }

    /**
     * Resolve a driver with a valid access token, telling the user what to do when that fails.
     */
    protected function authenticatedDriver(Nutgram $bot, string $failureMessage = 'You are not authenticated. Please use /start to register.'): ?BankDriver
    {
        $session = $this->findSession($bot);

        if (! $session) {
            $bot->sendMessage($failureMessage);

            return null;
        }

        $driver = $session->driver();

        if (! $driver->ensureAuthenticated()) {
            $bot->sendMessage($failureMessage);

            return null;
        }

        return $driver;
    }
}
