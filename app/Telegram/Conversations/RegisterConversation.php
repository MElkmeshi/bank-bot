<?php

namespace App\Telegram\Conversations;

use App\Enums\Bank;
use App\Exceptions\FirebaseBlockedException;
use App\Models\BankSession;
use App\Services\Banks\BankManager;
use App\Services\Banks\Contracts\BankDriver;
use App\Telegram\Concerns\ResolvesBankSession;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;

class RegisterConversation extends Conversation
{
    use ResolvesBankSession;

    private string $identifier = '';

    private string $secret = '';

    public function __construct(
        private Bank $bank,
    ) {}

    protected function getSerializableAttributes(): array
    {
        return [
            'bank' => $this->bank,
            'identifier' => $this->identifier,
            'secret' => $this->secret,
        ];
    }

    public function __unserialize(array $data): void
    {
        $this->bank = $data['bank'] ?? Bank::Andalus;
        $this->identifier = $data['identifier'] ?? '';
        $this->secret = $data['secret'] ?? '';

        // Restore parent private properties via closure binding
        $restoreParent = \Closure::bind(function (array $data) {
            $this->step = $data['step'] ?? 'start';
            $this->skipHandlers = $data['skipHandlers'] ?? false;
            $this->skipMiddlewares = $data['skipMiddlewares'] ?? false;
            $this->userId = $data['userId'] ?? null;
            $this->chatId = $data['chatId'] ?? null;
            $this->threadId = $data['threadId'] ?? null;
        }, $this, Conversation::class);

        $restoreParent($data);
    }

    public function start(Nutgram $bot): void
    {
        $session = $this->findSession($bot);

        if ($session && $session->isAuthenticated()) {
            $bot->sendMessage("You are already registered with {$this->bank->displayName()}. Use /balance to check your balance.");
            $this->end();

            return;
        }

        $prompts = $this->driver($session ?? new BankSession)->credentialPrompts();
        $hint = $prompts->identifier_hint ? " ({$prompts->identifier_hint})" : '';

        $bot->sendMessage("Welcome to {$this->bank->displayName()} bot!\n\nPlease enter your {$prompts->identifier_label}{$hint}:");
        $this->next('askSecret');
    }

    public function askSecret(Nutgram $bot): void
    {
        $this->identifier = trim($bot->message()->text ?? '');
        $prompts = $this->driver($this->findSession($bot) ?? new BankSession)->credentialPrompts();

        if (empty($this->identifier)) {
            $bot->sendMessage("{$prompts->identifier_label} cannot be empty. Please enter your {$prompts->identifier_label}:");

            return;
        }

        $bot->sendMessage("Please enter your {$prompts->secret_label}:\n\n⚠️ Never share your {$prompts->secret_label} with anyone.");
        $this->next('login');
    }

    public function login(Nutgram $bot): void
    {
        $this->secret = trim($bot->message()->text ?? '');

        if (empty($this->secret)) {
            $bot->sendMessage('This field cannot be empty. Please try again:');

            return;
        }

        $bot->sendMessage('Registering your device, please wait...');

        try {
            $session = BankSession::firstOrCreate(
                ['telegram_chat_id' => $bot->chatId(), 'bank' => $this->bank->value],
                ['customer_id' => $this->identifier, 'device_id' => ''],
            );

            $result = $this->driver($session)->login($this->identifier, $this->secret);
            $this->secret = '';

            if ($result->requires_otp) {
                $bot->sendMessage($result->otp_prompt ?? 'Please enter the verification code:');
                $this->next('verifyOtp');

                return;
            }

            $this->finish($bot, $result->customer_name);
        } catch (FirebaseBlockedException) {
            $bot->sendMessage('⚠️ Device registration is temporarily unavailable. Please try again later with /start');
            $this->end();
        } catch (\Throwable $e) {
            $this->sendError($bot, 'Registration failed', $e, 'Please try again with /start');
            $this->end();
        }
    }

    public function verifyOtp(Nutgram $bot): void
    {
        $otpCode = trim($bot->message()->text ?? '');

        if (empty($otpCode)) {
            $bot->sendMessage('Verification code cannot be empty. Please enter the code:');

            return;
        }

        $session = $this->findSession($bot);

        if (! $session) {
            $bot->sendMessage('Session expired. Please start again with /start');
            $this->end();

            return;
        }

        try {
            $result = $this->driver($session)->verifyOtp($otpCode);

            $this->finish($bot, $result->customer_name);
        } catch (\Throwable $e) {
            $this->sendError($bot, 'Verification failed', $e, 'Please enter the code again or restart with /start');
        }
    }

    private function finish(Nutgram $bot, ?string $customerName): void
    {
        $greeting = $customerName ? " Welcome, {$customerName}!" : '';

        $bot->sendMessage(
            "✅ Registration successful!{$greeting}\n\n"
            ."Available commands:\n"
            ."/balance - Check your account balance\n"
            ."/transactions - View recent transactions\n"
            ."/transfer - Send a bank transfer\n"
            ."/voucher - Buy a prepaid voucher\n"
            .'/delete_device - Remove this device'
        );

        $this->end();
    }

    private function driver(BankSession $session): BankDriver
    {
        return app(BankManager::class)->driver($this->bank, $session);
    }
}
