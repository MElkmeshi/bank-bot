<?php

namespace App\Console\Commands;

use App\Enums\Bank;
use App\Telegram\BotKernel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use SergiX44\Nutgram\Configuration;
use SergiX44\Nutgram\Nutgram;

class RunBotCommand extends Command
{
    protected $signature = 'bot:run {bank : The bank to run the bot for (andalus or nuran)}';

    protected $description = 'Run the Telegram bot for a specific bank';

    public function handle(): int
    {
        $bankValue = $this->argument('bank');

        $bank = Bank::tryFrom($bankValue);

        if ($bank === null) {
            $this->error("Unknown bank: {$bankValue}. Available banks: andalus, nuran");

            return self::FAILURE;
        }

        $token = $bank->config('telegram_token');

        if (empty($token)) {
            $this->error("Telegram token for {$bank->displayName()} is not configured.");

            return self::FAILURE;
        }

        $this->info("Starting {$bank->displayName()} bot...");

        $config = new Configuration(
            cache: Cache::store(),
        );

        $bot = new Nutgram($token, $config);

        BotKernel::register($bot, $bank);

        $bot->run();

        return self::SUCCESS;
    }
}
