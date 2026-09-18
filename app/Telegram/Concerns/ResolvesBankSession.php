<?php

namespace App\Telegram\Concerns;

use App\Enums\Bank;
use App\Models\BankSession;
use App\Services\Banks\Contracts\BankDriver;
use SergiX44\Nutgram\Nutgram;
use Throwable;

/**
 * Shared lookup of the chat's BankSession for the handler's bank.
 *
 * @property Bank $bank
 */
trait ResolvesBankSession
{
    /**
     * Telegram rejects messages longer than 4096 characters.
     */
    private const TELEGRAM_MESSAGE_LIMIT = 4096;

    /**
     * Tell the user what went wrong, with the bank's full error, only cut where Telegram forces it.
     */
    protected function sendError(Nutgram $bot, string $context, Throwable $exception, string $advice = ''): void
    {
        $message = "❌ {$context}:\n{$exception->getMessage()}";
        $suffix = $advice !== '' ? "\n\n{$advice}" : '';
        $limit = self::TELEGRAM_MESSAGE_LIMIT - mb_strlen($suffix) - 1;

        if (mb_strlen($message) > $limit) {
            $message = mb_substr($message, 0, $limit).'…';
        }

        $bot->sendMessage($message.$suffix);
    }

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
