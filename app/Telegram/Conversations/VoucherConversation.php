<?php

namespace App\Telegram\Conversations;

use App\Data\Bank\VoucherPurchaseRequestData;
use App\Data\Bank\VoucherQuoteData;
use App\Enums\Bank;
use App\Models\Voucher;
use App\Services\Banks\Contracts\BankDriver;
use App\Services\Banks\Contracts\SellsVouchers;
use App\Telegram\Concerns\ResolvesBankSession;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class VoucherConversation extends Conversation
{
    use ResolvesBankSession;

    private string $accountNumber = '';

    private string $providerId = '';

    private string $providerName = '';

    /** @var array<string, mixed>|null */
    private ?array $quote = null;

    public function __construct(
        private Bank $bank,
    ) {}

    protected function getSerializableAttributes(): array
    {
        return [
            'bank' => $this->bank,
            'accountNumber' => $this->accountNumber,
            'providerId' => $this->providerId,
            'providerName' => $this->providerName,
            'quote' => $this->quote,
        ];
    }

    public function __unserialize(array $data): void
    {
        $this->bank = $data['bank'] ?? Bank::Andalus;
        $this->accountNumber = $data['accountNumber'] ?? '';
        $this->providerId = $data['providerId'] ?? '';
        $this->providerName = $data['providerName'] ?? '';
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
        $driver = $this->voucherDriver($bot);

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

            $default = $driver->session()->default_account_number;

            if ($accounts->count() === 1 || $default !== null) {
                $this->accountNumber = $default ?? $accounts->first()->number;
                $this->showProviders($bot);

                return;
            }

            $keyboard = InlineKeyboardMarkup::make();

            foreach ($accounts as $account) {
                $keyboard->addRow(InlineKeyboardButton::make(
                    "📋 {$account->number} ({$account->available_balance_formatted})",
                    callback_data: "voucher_account:{$account->number}"
                ));
            }

            $bot->sendMessage("🎫 *Buy a voucher*\n\nChoose the account to pay from:", parse_mode: 'Markdown', reply_markup: $keyboard);
            $this->next('receiveAccount');
        } catch (\Throwable $e) {
            $this->sendError($bot, 'Failed to load accounts', $e);
            $this->end();
        }
    }

    public function receiveAccount(Nutgram $bot): void
    {
        $data = $bot->callbackQuery()?->data ?? '';

        if (! str_starts_with($data, 'voucher_account:')) {
            $bot->sendMessage('Please choose an account from the buttons above.');

            return;
        }

        $bot->answerCallbackQuery();
        $this->accountNumber = substr($data, strlen('voucher_account:'));
        $this->showProviders($bot);
    }

    private function showProviders(Nutgram $bot): void
    {
        $driver = $this->voucherDriver($bot);

        if (! $driver) {
            return;
        }

        try {
            $keyboard = InlineKeyboardMarkup::make();

            foreach ($driver->voucherProviders()->toCollection()->chunk(2) as $row) {
                $keyboard->addRow(...$row->map(fn ($provider) => InlineKeyboardButton::make(
                    $provider->name,
                    callback_data: "voucher_provider:{$provider->id}"
                ))->all());
            }

            $bot->sendMessage('Choose the operator / service:', reply_markup: $keyboard);
            $this->next('receiveProvider');
        } catch (\Throwable $e) {
            $this->sendError($bot, 'Failed to load voucher providers', $e);
            $this->end();
        }
    }

    public function receiveProvider(Nutgram $bot): void
    {
        $data = $bot->callbackQuery()?->data ?? '';

        if (! str_starts_with($data, 'voucher_provider:')) {
            $bot->sendMessage('Please choose an operator from the buttons above.');

            return;
        }

        $bot->answerCallbackQuery();
        $this->providerId = substr($data, strlen('voucher_provider:'));

        $driver = $this->voucherDriver($bot);

        if (! $driver) {
            return;
        }

        try {
            $this->providerName = $driver->voucherProviders()->toCollection()->firstWhere('id', $this->providerId)?->name ?? $this->providerId;
            $denominations = $driver->voucherDenominations($this->providerId);

            if ($denominations->count() === 0) {
                $bot->sendMessage("No vouchers are available for {$this->providerName} right now.");
                $this->end();

                return;
            }

            $keyboard = InlineKeyboardMarkup::make();

            foreach ($denominations->toCollection()->chunk(3) as $row) {
                $keyboard->addRow(...$row->map(fn ($denomination) => InlineKeyboardButton::make(
                    $denomination->label,
                    callback_data: "voucher_amount:{$denomination->id}"
                ))->all());
            }

            $bot->sendMessage("*{$this->providerName}* — choose the amount:", parse_mode: 'Markdown', reply_markup: $keyboard);
            $this->next('receiveAmount');
        } catch (\Throwable $e) {
            $this->sendError($bot, 'Failed to load voucher amounts', $e);
            $this->end();
        }
    }

    public function receiveAmount(Nutgram $bot): void
    {
        $data = $bot->callbackQuery()?->data ?? '';

        if (! str_starts_with($data, 'voucher_amount:')) {
            $bot->sendMessage('Please choose an amount from the buttons above.');

            return;
        }

        $bot->answerCallbackQuery();

        $driver = $this->voucherDriver($bot);

        if (! $driver) {
            return;
        }

        $bot->sendMessage('Preparing the purchase, please wait...');

        try {
            $quote = $driver->purchaseVoucher(new VoucherPurchaseRequestData(
                account_number: $this->accountNumber,
                provider_id: $this->providerId,
                denomination_id: substr($data, strlen('voucher_amount:')),
            ));

            $this->quote = $quote->toArray();

            $keyboard = InlineKeyboardMarkup::make()->addRow(
                InlineKeyboardButton::make('✅ Confirm', callback_data: 'voucher_confirm'),
                InlineKeyboardButton::make('❌ Cancel', callback_data: 'voucher_cancel'),
            );

            $bot->sendMessage(
                "🎫 *Voucher Summary*\n\n"
                ."Operator: *{$quote->provider_name}*\n"
                ."Amount: *{$quote->amount_formatted}*\n"
                ."From account: `{$quote->account_number}`",
                parse_mode: 'Markdown',
                reply_markup: $keyboard
            );
            $this->next('receiveConfirmation');
        } catch (\Throwable $e) {
            $this->sendError($bot, 'Voucher purchase failed', $e);
            $this->end();
        }
    }

    public function receiveConfirmation(Nutgram $bot): void
    {
        $data = $bot->callbackQuery()?->data ?? '';

        if ($data === 'voucher_cancel') {
            $bot->answerCallbackQuery(text: 'Purchase cancelled.');
            $bot->sendMessage('Voucher purchase has been cancelled.');
            $this->end();

            return;
        }

        if ($data !== 'voucher_confirm') {
            $bot->sendMessage('Please use the Confirm or Cancel buttons above.');

            return;
        }

        $bot->answerCallbackQuery();

        if ($this->quote['requires_otp'] ?? false) {
            $bot->sendMessage('An OTP has been sent to your phone. Please enter the verification code:');
            $this->next('receiveOtp');

            return;
        }

        $this->confirmPurchase($bot);
    }

    public function receiveOtp(Nutgram $bot): void
    {
        $otp = trim($bot->message()?->text ?? '');

        if ($otp === '') {
            $bot->sendMessage('Verification code cannot be empty. Please enter the code:');

            return;
        }

        $this->confirmPurchase($bot, $otp);
    }

    private function confirmPurchase(Nutgram $bot, ?string $otp = null): void
    {
        $driver = $this->voucherDriver($bot);

        if (! $driver || $this->quote === null) {
            return;
        }

        try {
            $voucher = $driver->confirmVoucherPurchase(VoucherQuoteData::from($this->quote), $otp);

            Voucher::record($driver->session(), $this->accountNumber, $voucher);

            $details = "✅ *Voucher purchased!*\n\n"
                ."Operator: *{$voucher->provider_name}*\n"
                ."Amount: *{$voucher->amount} {$voucher->currency}*\n"
                ."🔑 Code: `{$voucher->code}`";

            if ($voucher->serial) {
                $details .= "\nSerial: `{$voucher->serial}`";
            }

            if ($voucher->reference) {
                $details .= "\nReference: `{$voucher->reference}`";
            }

            $bot->sendMessage($details, parse_mode: 'Markdown');
            $this->end();
        } catch (\Throwable $e) {
            $this->sendError($bot, 'Voucher purchase failed', $e);
            $this->end();
        }
    }

    /**
     * @return (BankDriver&SellsVouchers)|null
     */
    private function voucherDriver(Nutgram $bot): ?BankDriver
    {
        $driver = $this->authenticatedDriver($bot);

        if (! $driver) {
            $this->end();

            return null;
        }

        if (! $driver instanceof SellsVouchers) {
            $bot->sendMessage("Voucher purchases are not available for {$this->bank->displayName()} yet.");
            $this->end();

            return null;
        }

        return $driver;
    }
}
