<?php

namespace App\Telegram;

use Illuminate\Support\Facades\Log;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\RunningMode\Polling;
use Throwable;

/**
 * Long polling that survives transient Telegram connectivity problems.
 *
 * Nutgram's default polling lets a failed getUpdates call (timeout, DNS
 * hiccup, 5xx) escape and kill the process. Here the failure is logged and
 * polling resumes after a short pause instead.
 */
class ResilientPolling extends Polling
{
    public function __construct(
        private readonly int $retryDelaySeconds = 3,
    ) {
        parent::__construct();
    }

    public function processUpdates(Nutgram $bot): void
    {
        $this->listenForSignals();

        $config = $bot->getConfig();
        $offset = 1;

        echo "Listening...\n";

        while (self::$FOREVER) {
            try {
                $updates = $bot->getUpdates(
                    offset: $offset,
                    limit: $config->pollingLimit,
                    timeout: $config->pollingTimeout,
                    allowed_updates: $config->pollingAllowedUpdates
                );
            } catch (Throwable $e) {
                Log::warning('Telegram polling failed, retrying', ['error' => $e->getMessage()]);
                sleep($this->retryDelaySeconds);

                continue;
            }

            if ($offset === 1) {
                $last = end($updates);

                if ($last) {
                    $offset = $last->update_id;
                }

                continue;
            }

            $offset += count($updates);

            $this->fire($bot, $updates);
        }
    }
}
