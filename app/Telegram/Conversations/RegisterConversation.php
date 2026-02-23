<?php

namespace App\Telegram\Conversations;

use App\Data\Bank\RegisterRequestData;
use App\Data\Bank\VerifyOtpRequestData;
use App\Enums\Bank;
use App\Exceptions\FirebaseBlockedException;
use App\Models\BankSession;
use App\Services\BankApiService;
use App\Services\FirebaseService;
use Carbon\Carbon;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;

class RegisterConversation extends Conversation
{
    private string $customerId = '';

    private string $nationalId = '';

    private string $phoneNumber = '';

    private string $password = '';

    public function __construct(
        private Bank $bank,
        private FirebaseService $firebaseService,
    ) {}

    protected function getSerializableAttributes(): array
    {
        return [
            'bank' => $this->bank,
            'firebaseService' => $this->firebaseService,
            'customerId' => $this->customerId,
            'nationalId' => $this->nationalId,
            'phoneNumber' => $this->phoneNumber,
            'password' => $this->password,
        ];
    }

    public function __unserialize(array $data): void
    {
        $this->bank = $data['bank'] ?? Bank::Andalus;
        $this->firebaseService = $data['firebaseService'] ?? new FirebaseService;
        $this->customerId = $data['customerId'] ?? '';
        $this->nationalId = $data['nationalId'] ?? '';
        $this->phoneNumber = $data['phoneNumber'] ?? '';
        $this->password = $data['password'] ?? '';

        // Restore parent private properties via closure binding
        $restoreParent = \Closure::bind(function (array $data) {
            $this->step = $data['step'] ?? 'start';
            $this->skipHandlers = $data['skipHandlers'] ?? false;
            $this->skipMiddlewares = $data['skipMiddlewares'] ?? false;
            $this->userId = $data['userId'] ?? null;
            $this->chatId = $data['chatId'] ?? null;
            $this->threadId = $data['threadId'] ?? null;
        }, $this, \SergiX44\Nutgram\Conversations\Conversation::class);

        $restoreParent($data);
    }

    public function start(Nutgram $bot): void
    {
        $chatId = $bot->chatId();

        $session = BankSession::where('telegram_chat_id', $chatId)
            ->where('bank', $this->bank->value)
            ->first();

        if ($session && $session->isAuthenticated()) {
            $bot->sendMessage("You are already registered with {$this->bank->displayName()}. Use /balance to check your balance.");
            $this->end();

            return;
        }

        $bot->sendMessage("Welcome to {$this->bank->displayName()} bot!\n\nPlease enter your Customer ID:");
        $this->next('askNationalId');
    }

    public function askNationalId(Nutgram $bot): void
    {
        $this->customerId = trim($bot->message()->text ?? '');

        if (empty($this->customerId)) {
            $bot->sendMessage('Customer ID cannot be empty. Please enter your Customer ID:');

            return;
        }

        $bot->sendMessage('Please enter your National ID:');
        $this->next('askPhone');
    }

    public function askPhone(Nutgram $bot): void
    {
        $this->nationalId = trim($bot->message()->text ?? '');

        if (empty($this->nationalId)) {
            $bot->sendMessage('National ID cannot be empty. Please enter your National ID:');

            return;
        }

        $bot->sendMessage('Please enter your Phone Number:');
        $this->next('askPassword');
    }

    public function askPassword(Nutgram $bot): void
    {
        $this->phoneNumber = trim($bot->message()->text ?? '');

        if (empty($this->phoneNumber)) {
            $bot->sendMessage('Phone number cannot be empty. Please enter your Phone Number:');

            return;
        }

        $bot->sendMessage("Please enter your Password:\n\n⚠️ Never share your password with anyone.");
        $this->next('generateDeviceAndRegister');
    }

    public function generateDeviceAndRegister(Nutgram $bot): void
    {
        $this->password = trim($bot->message()->text ?? '');

        if (empty($this->password)) {
            $bot->sendMessage('Password cannot be empty. Please enter your Password:');

            return;
        }

        $bot->sendMessage('Registering your device, please wait...');

        try {
            $deviceId = $this->firebaseService->getInstallationId($this->bank);

            $registerData = RegisterRequestData::from([
                'customer_id' => $this->customerId,
                'national_id' => $this->nationalId,
                'phone_number' => $this->phoneNumber,
                'device_id' => $deviceId,
                'password' => $this->password,
            ]);

            $apiService = new BankApiService($this->bank, $deviceId);
            $registerResponse = $apiService->register($registerData);

            BankSession::updateOrCreate(
                [
                    'telegram_chat_id' => $bot->chatId(),
                    'bank' => $this->bank->value,
                ],
                [
                    'customer_id' => $this->customerId,
                    'device_id' => $deviceId,
                    'verification_reference' => $registerResponse->verification_reference,
                    'access_token' => null,
                    'refresh_token' => null,
                    'access_token_expires_at' => null,
                    'refresh_token_expires_at' => null,
                ]
            );

            $bot->sendMessage('An OTP has been sent to your phone. Please enter the verification code:');
            $this->next('verifyOtp');
        } catch (FirebaseBlockedException) {
            $bot->sendMessage('⚠️ Device registration is temporarily unavailable. Please try again later with /start');
            $this->end();
        } catch (\Throwable $e) {
            $bot->sendMessage("Registration failed: {$e->getMessage()}\n\nPlease try again with /start");
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

        $session = BankSession::where('telegram_chat_id', $bot->chatId())
            ->where('bank', $this->bank->value)
            ->first();

        if (! $session || ! $session->verification_reference) {
            $bot->sendMessage('Session expired. Please start again with /start');
            $this->end();

            return;
        }

        try {
            $apiService = new BankApiService($this->bank, $session->device_id);

            $verifyData = VerifyOtpRequestData::from([
                'verification_reference' => $session->verification_reference,
                'customer_id' => $session->customer_id,
                'verification_code' => $otpCode,
            ]);

            $verifyResponse = $apiService->verifyOtp($verifyData);

            $session->update([
                'access_token' => $verifyResponse->access_token,
                'refresh_token' => $verifyResponse->refresh_token,
                'access_token_expires_at' => Carbon::createFromTimestamp($verifyResponse->access_token_expires_at),
                'refresh_token_expires_at' => Carbon::createFromTimestamp($verifyResponse->refresh_token_expires_at),
                'verification_reference' => null,
            ]);

            $bot->sendMessage(
                "✅ Registration successful! Welcome, {$verifyResponse->customer->name}!\n\n"
                ."Available commands:\n"
                ."/balance - Check your account balance\n"
                ."/transactions - View recent transactions\n"
                ."/transfer - Send a bank transfer\n"
                .'/delete_device - Remove this device'
            );

            $this->end();
        } catch (\Throwable $e) {
            $bot->sendMessage("Verification failed: {$e->getMessage()}\n\nPlease enter the code again or restart with /start");
        }
    }
}
