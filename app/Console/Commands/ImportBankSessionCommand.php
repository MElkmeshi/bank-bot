<?php

namespace App\Console\Commands;

use App\Enums\Bank;
use App\Models\BankSession;
use Illuminate\Console\Command;
use Throwable;

class ImportBankSessionCommand extends Command
{
    protected $signature = 'bank:import-session
        {bank : The bank slug (see config/banks.php)}
        {--chat= : Telegram chat ID that owns the session}
        {--identifier= : Login identifier (customer ID / phone number)}
        {--secret= : Login secret (password / PIN); prompted when omitted}
        {--device= : Device ID / device key already registered with the bank}
        {--meta=* : Driver metadata as key=value (e.g. soft_token=..., app_id=..., activated=1)}
        {--no-verify : Skip logging in to verify the imported session}';

    protected $description = 'Import an already registered device/login from another project into a bank session';

    public function handle(): int
    {
        $bank = Bank::tryFrom($this->argument('bank'));

        if ($bank === null) {
            $this->error('Unknown bank. Available banks: '.implode(', ', Bank::values()));

            return self::FAILURE;
        }

        $chatId = (int) ($this->option('chat') ?: $this->ask('Telegram chat ID'));
        $identifier = $this->option('identifier') ?: $this->ask('Login identifier');
        $secret = $this->option('secret') ?: $this->secret('Login secret');
        $deviceId = $this->option('device') ?: $this->ask('Registered device ID / key');

        $session = BankSession::updateOrCreate(
            ['telegram_chat_id' => $chatId, 'bank' => $bank->value],
            [
                'customer_id' => $identifier,
                'device_id' => $deviceId,
                'credentials' => $this->credentialsFor($bank, $identifier, $secret),
                'meta' => $this->parseMeta(),
                'verification_reference' => null,
            ],
        );
        $session->clearTokens();

        $this->info("Session [{$session->id}] for {$bank->displayName()} saved.");

        if ($this->option('no-verify')) {
            return self::SUCCESS;
        }

        return $this->verify($session);
    }

    /** @return array<string, string> */
    private function credentialsFor(Bank $bank, string $identifier, string $secret): array
    {
        return match ($bank->driver()) {
            'nab' => ['phone' => $identifier, 'password' => $secret],
            'jumhouria' => ['phone' => $identifier, 'pin' => $secret],
            default => ['identifier' => $identifier, 'secret' => $secret],
        };
    }

    /** @return array<string, mixed> */
    private function parseMeta(): array
    {
        $meta = [];

        foreach ($this->option('meta') as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');

            $meta[$key] = match ($value) {
                '1', 'true' => true,
                '0', 'false' => false,
                default => is_numeric($value) ? $value + 0 : $value,
            };
        }

        return $meta;
    }

    private function verify(BankSession $session): int
    {
        $this->line('Logging in to verify the imported session...');

        try {
            $driver = $session->driver();

            if (! $driver->ensureAuthenticated()) {
                $this->error('The bank did not accept the imported credentials/device.');

                return self::FAILURE;
            }

            $accounts = $driver->accounts();
        } catch (Throwable $e) {
            $this->error("Verification failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info('Login OK. Token valid until '.$session->fresh()->access_token_expires_at?->toDateTimeString());

        $this->table(
            ['Account', 'Currency', 'Balance', 'IBAN'],
            $accounts->toCollection()->map(fn ($account) => [
                $account->number,
                $account->currency,
                $account->available_balance_formatted,
                $account->iban ?? '-',
            ]),
        );

        return self::SUCCESS;
    }
}
