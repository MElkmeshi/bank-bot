<?php

namespace App\Telegram\Conversations;

use App\Data\Bank\TransferQuoteData;
use App\Data\Bank\TransferRequestData;
use App\Enums\Bank;
use App\Services\Banks\Contracts\BankDriver;
use App\Telegram\Concerns\ResolvesBankSession;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class TransferConversation extends Conversation
{
    use ResolvesBankSession;

    private string $sourceAccount = '';

    private string $iban = '';

    private string $amount = '';

    private string $description = '';

    /** @var array<string, mixed>|null */
    private ?array $quote = null;

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
            'quote' => $this->quote,
        ];
    }

    public function __unserialize(array $data): void
    {
        $this->bank = $data['bank'] ?? Bank::Andalus;
        $this->sourceAccount = $data['sourceAccount'] ?? '';
        $this->iban = $data['iban'] ?? '';
        $this->amount = $data['amount'] ?? '';
        $this->description = $data['description'] ?? '';
        $this->quote = $data['quote'] ?? null;

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
        $driver = $this->getAuthenticatedDriver($bot);

        if (! $driver) {
            return;
        }

        try {
            $accounts = $driver->accounts();

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
            $this->sendError($bot, 'Failed to load accounts', $e);
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
        $driver = $this->getAuthenticatedDriver($bot);

        if (! $driver) {
            return;
        }

        try {
            $contacts = $driver->contacts();

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
            $this->sendError($bot, 'Failed to load contacts', $e);
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
        $driver = $this->getAuthenticatedDriver($bot);

        if (! $driver) {
            return;
        }

        $bot->sendMessage('Processing transfer, please wait...');

        try {
            $quote = $driver->initiateTransfer(new TransferRequestData(
                debtor_account_number: $this->sourceAccount,
                iban: $this->iban,
                amount: $this->amount,
                currency: 'LYD',
                description: $this->description ?: null,
            ));

            $this->quote = $quote->toArray();

            $debtorName = $quote->debtor_name ?? 'N/A';
            $creditorName = $quote->creditor_name ?? 'N/A';

            $summary = "📋 *Transfer Summary*\n\n"
                ."From: *{$debtorName}*\n"
                ."To: *{$creditorName}* (`{$quote->creditor_identification}`)\n"
                ."Amount: *{$quote->amount_formatted}*\n"
                .'Fees: *'.($quote->fees_formatted ?? 'N/A')."*\n"
                ."Total: *{$quote->total_amount_formatted}*\n"
                ."Currency: {$quote->currency}";

            if ($quote->description) {
                $summary .= "\nNote: _{$quote->description}_";
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
            $this->sendError($bot, 'Transfer failed', $e);
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

            if ($this->quote['requires_otp'] ?? false) {
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
        $driver = $this->getAuthenticatedDriver($bot);

        if (! $driver || $this->quote === null) {
            return;
        }

        try {
            $receipt = $driver->confirmTransfer(TransferQuoteData::from($this->quote), $otpCode);

            $reference = $receipt->reference ? "\nReference: `{$receipt->reference}`" : '';

            $bot->sendMessage("✅ Transfer completed successfully!{$reference}\n\nUse /balance to check your updated balance.", parse_mode: 'Markdown');
            $this->end();
        } catch (\Throwable $e) {
            $this->sendError($bot, 'Transfer confirmation failed', $e);
            $this->end();
        }
    }

    private function getAuthenticatedDriver(Nutgram $bot): ?BankDriver
    {
        $driver = $this->authenticatedDriver($bot);

        if (! $driver) {
            $this->end();
        }

        return $driver;
    }
}
