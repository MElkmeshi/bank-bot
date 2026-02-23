<?php

namespace App\Telegram\Conversations;

use App\Enums\Bank;
use App\Models\BankSession;
use App\Services\BankApiService;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class TransferConversation extends Conversation
{
    private string $sourceAccount = '';

    private string $iban = '';

    private string $amount = '';

    private string $description = '';

    private string $transactionId = '';

    private bool $requiresOtp = false;

    private string $verificationReference = '';

    public function __construct(
        private Bank $bank,
    ) {}

    protected function getSerializableAttributes(): array
    {
        return [
            'bank' => $this->bank,
            'sourceAccount' => $this->sourceAccount,
            'iban' => $this->iban,
            'amount' => $this->amount,
            'description' => $this->description,
            'transactionId' => $this->transactionId,
            'requiresOtp' => $this->requiresOtp,
            'verificationReference' => $this->verificationReference,
        ];
    }

    public function __unserialize(array $data): void
    {
        $this->bank = $data['bank'] ?? Bank::Andalus;
        $this->sourceAccount = $data['sourceAccount'] ?? '';
        $this->iban = $data['iban'] ?? '';
        $this->amount = $data['amount'] ?? '';
        $this->description = $data['description'] ?? '';
        $this->transactionId = $data['transactionId'] ?? '';
        $this->requiresOtp = $data['requiresOtp'] ?? false;
        $this->verificationReference = $data['verificationReference'] ?? '';

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
        $session = $this->getAuthenticatedSession($bot);

        if (! $session) {
            return;
        }

        try {
            $apiService = new BankApiService($this->bank, $session->device_id, $session->access_token);
            $accounts = $apiService->getAccounts();

            if ($accounts->count() === 0) {
                $bot->sendMessage('No accounts found.');
                $this->end();

                return;
            }

            if ($accounts->count() === 1) {
                $this->sourceAccount = $accounts->first()->number;
                $this->showContacts($bot);

                return;
            }

            $keyboard = InlineKeyboardMarkup::make();

            foreach ($accounts as $account) {
                $keyboard->addRow(
                    InlineKeyboardButton::make(
                        "📋 {$account->number} ({$account->available_balance_formatted})",
                        callback_data: "transfer_account:{$account->number}"
                    )
                );
            }

            $bot->sendMessage(
                "💸 *Transfer Money*\n\nChoose the source account:",
                parse_mode: 'Markdown',
                reply_markup: $keyboard
            );
            $this->next('receiveAccountSelection');
        } catch (\Throwable $e) {
            $bot->sendMessage("Failed to load accounts: {$e->getMessage()}");
            $this->end();
        }
    }

    public function receiveAccountSelection(Nutgram $bot): void
    {
        $callbackQuery = $bot->callbackQuery();

        if (! $callbackQuery || ! str_starts_with($callbackQuery->data ?? '', 'transfer_account:')) {
            $bot->sendMessage('Please select an account from the buttons above.');

            return;
        }

        $bot->answerCallbackQuery();

        [, $accountNumber] = explode(':', $callbackQuery->data, 2);

        $this->sourceAccount = $accountNumber;
        $this->showContacts($bot);
    }

    private function showContacts(Nutgram $bot): void
    {
        $session = $this->getAuthenticatedSession($bot);

        if (! $session) {
            return;
        }

        try {
            $apiService = new BankApiService($this->bank, $session->device_id, $session->access_token);
            $contacts = $apiService->getContacts();

            $keyboard = InlineKeyboardMarkup::make();

            foreach ($contacts as $contact) {
                $truncatedIban = '...'.substr($contact->identification, -8);
                $keyboard->addRow(
                    InlineKeyboardButton::make(
                        "{$contact->name} ({$truncatedIban})",
                        callback_data: "transfer_contact:{$contact->identification}"
                    )
                );
            }

            $keyboard->addRow(
                InlineKeyboardButton::make(
                    '✏️ Enter new IBAN',
                    callback_data: 'transfer_new_iban'
                )
            );

            $bot->sendMessage(
                'Choose a contact or enter a new IBAN:',
                reply_markup: $keyboard
            );
            $this->next('receiveContactSelection');
        } catch (\Throwable $e) {
            $bot->sendMessage("Failed to load contacts: {$e->getMessage()}");
            $this->end();
        }
    }

    public function receiveContactSelection(Nutgram $bot): void
    {
        $callbackQuery = $bot->callbackQuery();

        if (! $callbackQuery) {
            $bot->sendMessage('Please select a contact from the buttons above.');

            return;
        }

        $data = $callbackQuery->data ?? '';

        if ($data === 'transfer_new_iban') {
            $bot->answerCallbackQuery();
            $bot->sendMessage('Please enter the IBAN:');
            $this->next('receiveIban');

            return;
        }

        if (str_starts_with($data, 'transfer_contact:')) {
            $bot->answerCallbackQuery();

            [, $iban] = explode(':', $data, 2);
            $this->iban = $iban;

            $bot->sendMessage('Enter the transfer amount (e.g. 100.00):');
            $this->next('receiveAmount');

            return;
        }

        $bot->sendMessage('Please select a contact from the buttons above.');
    }

    public function receiveIban(Nutgram $bot): void
    {
        $iban = trim($bot->message()->text ?? '');

        if (empty($iban)) {
            $bot->sendMessage('IBAN cannot be empty. Please enter the IBAN:');

            return;
        }

        $this->iban = $iban;

        $bot->sendMessage('Enter the transfer amount (e.g. 100.00):');
        $this->next('receiveAmount');
    }

    public function receiveAmount(Nutgram $bot): void
    {
        $amount = trim($bot->message()->text ?? '');

        if (empty($amount) || ! is_numeric($amount) || (float) $amount <= 0) {
            $bot->sendMessage('Please enter a valid amount greater than 0:');

            return;
        }

        $this->amount = $amount;

        $bot->sendMessage('Enter a description (or send `-` to skip):');
        $this->next('receiveDescription');
    }

    public function receiveDescription(Nutgram $bot): void
    {
        $text = trim($bot->message()->text ?? '');

        if ($text === '-' || $text === 'skip') {
            $this->description = '';
        } else {
            $this->description = $text;
        }

        $this->initiateTransfer($bot);
    }

    private function initiateTransfer(Nutgram $bot): void
    {
        $session = $this->getAuthenticatedSession($bot);

        if (! $session) {
            return;
        }

        $bot->sendMessage('Processing transfer, please wait...');

        try {
            $apiService = new BankApiService($this->bank, $session->device_id, $session->access_token);

            $response = $apiService->initiateTransfer(
                debtorAccountNumber: $this->sourceAccount,
                iban: $this->iban,
                amount: $this->amount,
                currency: 'LYD',
                description: $this->description ?: null,
            );

            $this->transactionId = $response->transaction_id;
            $this->requiresOtp = $response->requires_otp;
            $this->verificationReference = $response->verification_reference ?? '';

            $debtorName = $response->debtor['name'] ?? 'N/A';
            $creditorName = $response->creditor['name'] ?? 'N/A';
            $creditorIban = $response->creditor['identification'] ?? $this->iban;

            $summary = "📋 *Transfer Summary*\n\n"
                ."From: *{$debtorName}*\n"
                ."To: *{$creditorName}* (`{$creditorIban}`)\n"
                ."Amount: *{$response->original_amount_formatted}*\n"
                ."Fees: *{$response->fees_formatted}*\n"
                ."Total: *{$response->total_amount_formatted}*\n"
                ."Currency: {$response->currency}";

            if ($response->description) {
                $summary .= "\nNote: _{$response->description}_";
            }

            $keyboard = InlineKeyboardMarkup::make()
                ->addRow(
                    InlineKeyboardButton::make(
                        '✅ Confirm',
                        callback_data: 'transfer_confirm'
                    ),
                    InlineKeyboardButton::make(
                        '❌ Cancel',
                        callback_data: 'transfer_cancel'
                    )
                );

            $bot->sendMessage($summary, parse_mode: 'Markdown', reply_markup: $keyboard);
            $this->next('receiveConfirmation');
        } catch (\Throwable $e) {
            $bot->sendMessage("Transfer failed: {$e->getMessage()}");
            $this->end();
        }
    }

    public function receiveConfirmation(Nutgram $bot): void
    {
        $callbackQuery = $bot->callbackQuery();

        if (! $callbackQuery) {
            $bot->sendMessage('Please use the Confirm or Cancel buttons above.');

            return;
        }

        $data = $callbackQuery->data ?? '';

        if ($data === 'transfer_cancel') {
            $bot->answerCallbackQuery(text: 'Transfer cancelled.');
            $bot->sendMessage('Transfer has been cancelled.');
            $this->end();

            return;
        }

        if ($data === 'transfer_confirm') {
            $bot->answerCallbackQuery();

            if ($this->requiresOtp) {
                $bot->sendMessage('An OTP has been sent to your phone. Please enter the verification code:');
                $this->next('receiveOtp');

                return;
            }

            $this->confirmTransfer($bot);

            return;
        }

        $bot->sendMessage('Please use the Confirm or Cancel buttons above.');
    }

    public function receiveOtp(Nutgram $bot): void
    {
        $otpCode = trim($bot->message()->text ?? '');

        if (empty($otpCode)) {
            $bot->sendMessage('Verification code cannot be empty. Please enter the code:');

            return;
        }

        $this->confirmTransfer($bot, $otpCode);
    }

    private function confirmTransfer(Nutgram $bot, ?string $otpCode = null): void
    {
        $session = $this->getAuthenticatedSession($bot);

        if (! $session) {
            return;
        }

        try {
            $apiService = new BankApiService($this->bank, $session->device_id, $session->access_token);

            $apiService->confirmTransfer(
                transactionId: $this->transactionId,
                customerId: $otpCode !== null ? $session->customer_id : null,
                verificationReference: $otpCode !== null ? $this->verificationReference : null,
                verificationCode: $otpCode,
            );

            $bot->sendMessage("✅ Transfer completed successfully!\n\nUse /balance to check your updated balance.");
            $this->end();
        } catch (\Throwable $e) {
            $bot->sendMessage("Transfer confirmation failed: {$e->getMessage()}");
            $this->end();
        }
    }

    private function getAuthenticatedSession(Nutgram $bot): ?BankSession
    {
        $session = BankSession::where('telegram_chat_id', $bot->chatId())
            ->where('bank', $this->bank->value)
            ->first();

        if (! $session || (! $session->isAuthenticated() && ! $session->refreshTokenIfNeeded())) {
            $bot->sendMessage('You are not authenticated. Please use /start to register.');
            $this->end();

            return null;
        }

        return $session;
    }
}
