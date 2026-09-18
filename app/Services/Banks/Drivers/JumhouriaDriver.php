<?php

namespace App\Services\Banks\Drivers;

use App\Data\Bank\AccountData;
use App\Data\Bank\ContactData;
use App\Data\Bank\CredentialPromptsData;
use App\Data\Bank\LoginResultData;
use App\Data\Bank\TransactionData;
use App\Data\Bank\TransferQuoteData;
use App\Data\Bank\TransferReceiptData;
use App\Data\Bank\TransferRequestData;
use App\Enums\Bank;
use App\Exceptions\BankApiException;
use App\Models\BankSession;
use App\Services\Banks\AbstractBankDriver;
use App\Services\Banks\Support\LibyanIban;
use App\Services\FirebaseService;
use Carbon\Carbon;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\LaravelData\DataCollection;
use Throwable;

/**
 * Driver for Jumhouria Bank's "JamBank / +Plus" mobile channel.
 *
 * Login is a chain: an anonymous Firebase sign-in opens an API session, a
 * device-bound "soft token" produces a server-side OTP, and phone + PIN + OTP
 * yield the access token. New devices must additionally be activated with an
 * SMS code. Access tokens are bound to one account at a time, so the driver
 * re-signs into the right account before account-scoped calls.
 *
 * Every response is wrapped in {content, type, messages, traceId} where type 1 is success.
 */
class JumhouriaDriver extends AbstractBankDriver
{
    private const CURRENCIES = [1 => 'LYD', 2 => 'USD'];

    private const RESPONSE_SUCCESS = 1;

    public function __construct(
        Bank $bank,
        BankSession $session,
        private readonly FirebaseService $firebase,
    ) {
        parent::__construct($bank, $session);
    }

    public function credentialPrompts(): CredentialPromptsData
    {
        return new CredentialPromptsData(
            identifier_label: 'Phone number',
            secret_label: 'PIN',
            identifier_hint: 'with country code, e.g. 21892xxxxxxx',
        );
    }

    public function login(string $identifier, string $secret): LoginResultData
    {
        $this->session->update([
            'customer_id' => $identifier,
            'device_id' => $this->session->device_id ?: (string) Str::uuid(),
            'credentials' => ['phone' => $identifier, 'pin' => $secret],
        ]);

        $this->signIn();

        if (! $this->meta('activated', false)) {
            return LoginResultData::pendingOtp('An activation code has been sent to your phone by SMS. Please enter it:');
        }

        return LoginResultData::authenticated($this->customerName());
    }

    public function verifyOtp(string $code): LoginResultData
    {
        $this->unwrap($this->request()->withQueryParameters(['activeCode' => $code])->post('/AfradAuth/Activate'));

        $this->bindSoftToken();
        $this->rememberMeta(['activated' => true]);

        return LoginResultData::authenticated($this->customerName());
    }

    protected function reauthenticate(): bool
    {
        if ($this->credential('pin') === null) {
            return false;
        }

        $this->signIn();

        return true;
    }

    public function accounts(): DataCollection
    {
        $accounts = [];

        foreach ($this->bankAccounts() as $account) {
            $currency = self::CURRENCIES[$account['currType']] ?? 'LYD';
            $balance = $this->balanceOf($account['accNo']);

            $accounts[] = [
                'number' => $account['accNo'],
                'available_balance' => $balance ?? '0',
                'available_balance_formatted' => $balance !== null ? $this->formatAmount($balance, $currency) : 'unavailable',
                'currency' => $currency,
                'description' => trim($account['branch'] ?? ''),
                'iban' => $this->ibanFor($account['accNo']),
            ];
        }

        return AccountData::collect($accounts, DataCollection::class);
    }

    public function transactions(string $accountNumber): DataCollection
    {
        $currency = self::CURRENCIES[$this->bankAccount($accountNumber)['currType'] ?? 1] ?? 'LYD';
        $statements = $this->rawTransactions($accountNumber)['content']['bankStatements'] ?? [];
        $seen = [];
        $transactions = [];

        foreach ($statements as $statement) {
            $type = ($statement['typeStr'] ?? '') === 'C' ? TransactionData::TYPE_CREDIT : TransactionData::TYPE_DEBIT;
            $date = Carbon::createFromFormat('d/m/Y', $statement['postDate'])->startOfDay()->toDateTimeString();
            $description = $statement['descrption'] ?? '';
            $fingerprint = implode('|', [$date, $statement['amount'], $description, $type]);
            $seen[$fingerprint] = ($seen[$fingerprint] ?? 0) + 1;

            $transactions[] = [
                'reference' => $statement['sequenceNumber'] ?? 'JB-'.substr(sha1($fingerprint.'|'.$seen[$fingerprint]), 0, 20),
                'type' => $type,
                'type_label' => $type === TransactionData::TYPE_CREDIT ? 'Credit' : 'Debit',
                'date' => $date,
                'amount' => (string) $statement['amount'],
                'amount_formatted' => $this->formatAmount($statement['amount'], $currency),
                'currency' => $currency,
                'code_description' => $description,
                'description' => $description,
            ];
        }

        return TransactionData::collect($transactions, DataCollection::class);
    }

    public function rawTransactions(string $accountNumber): mixed
    {
        $this->selectAccount($accountNumber);

        return $this->checked($this->request()->get('/Inquiries/LastAccountStatement', ['accountType' => 0]))->json();
    }

    public function contacts(): DataCollection
    {
        return ContactData::collect(array_map(fn (array $friend) => [
            'name' => $friend['friendName'],
            'identification' => $friend['friendAccountNumberOrIban'],
            'schema' => LibyanIban::isValid($friend['friendAccountNumberOrIban']) ? ContactData::SCHEMA_IBAN : ContactData::SCHEMA_ACCOUNT,
            'institution_code' => $friend['friendCblBankCode'] ?? null,
            'institution_name' => $friend['bankName'] ?? null,
        ], $this->friends()), DataCollection::class);
    }

    public function initiateTransfer(TransferRequestData $request): TransferQuoteData
    {
        $toIban = LibyanIban::normalize($request->iban);
        $friend = $this->friendFor($toIban) ?? $this->addFriend($toIban);

        $this->selectAccount($request->debtor_account_number);
        $this->unwrap($this->request()->get('/AfradAuth/ResendActiveCode', ['otpType' => 0]));

        return new TransferQuoteData(
            amount_formatted: $this->formatAmount($request->amount, $request->currency),
            total_amount_formatted: $this->formatAmount($request->amount, $request->currency),
            currency: $request->currency,
            creditor_identification: $toIban,
            requires_otp: true,
            description: $request->description,
            debtor_name: $this->customerName(),
            creditor_name: $friend['friendName'],
            meta: [
                'amount' => $request->amount,
                'from_iban' => $this->ibanFor($request->debtor_account_number),
                'debtor_account_number' => $request->debtor_account_number,
            ],
        );
    }

    public function confirmTransfer(TransferQuoteData $quote, ?string $otp = null): TransferReceiptData
    {
        $this->selectAccount($quote->meta['debtor_account_number']);

        $response = $this->checked($this->request()->post('/Transactions/CrossBankMoneyTransaction', [
            'fromIban' => $quote->meta['from_iban'],
            'toIban' => $quote->creditor_identification,
            'amount' => (float) $quote->meta['amount'],
            'friendName' => $quote->creditor_name,
            'description' => $quote->description ?? '',
            'otp' => $otp,
            'crossBankWay' => 2,
            'validationErrors' => [],
        ]));

        $message = trim((string) ($response->json('messages.0') ?? ''));

        preg_match('/\b(\d{3}MT\d+)\b/u', $message, $matches);

        return new TransferReceiptData(reference: $matches[1] ?? null, message: $message ?: null);
    }

    /**
     * Run the full sign-in chain and store the resulting access token.
     */
    private function signIn(): void
    {
        $sessionToken = $this->openSession();
        $softToken = $this->meta('soft_token') ?? Str::upper(str_replace('-', '', (string) Str::uuid()));

        $this->rememberMeta(['soft_token' => $softToken]);

        $otp = $this->unwrap($this->http()->withToken($sessionToken)->get('/AfradAuth/SoftOtp', ['softToken' => $softToken]));

        $auth = $this->unwrap($this->http()->withToken($sessionToken)->post('/AfradAuth/Signin', [
            'userName' => $this->credential('phone'),
            'pin' => $this->credential('pin'),
            'otp' => (string) $otp,
        ]));

        $this->storeToken($auth);
        $this->rememberMeta(['selected_account' => null]);
    }

    /**
     * Open an anonymous API session for this device and return its session token.
     */
    private function openSession(): string
    {
        $content = $this->unwrap(
            $this->http()
                ->asMultipart()
                ->post('/AfradAuth/OpenSession', [
                    ['name' => 'AppId', 'contents' => (string) $this->meta('app_id', 0)],
                    ['name' => 'SignToken', 'contents' => $this->firebase->anonymousIdToken($this->bank)],
                    ['name' => 'DeviceID', 'contents' => $this->session->device_id],
                    ['name' => 'NotificationsToken', 'contents' => 'telegram-bot'],
                    ['name' => 'ProductType', 'contents' => (string) $this->bank->config('product_type')],
                    ['name' => 'Version', 'contents' => (string) $this->bank->config('app_version')],
                    ['name' => 'DeviceType', 'contents' => '0'],
                    ['name' => 'DeviceInfo', 'contents' => 'Telegram Bot'],
                ])
        );

        $this->rememberMeta(['app_id' => $content['appId'] ?? $this->meta('app_id', 0)]);

        return $content['sessionToken'];
    }

    /**
     * Register the soft token with the current PIN so future logins can skip SMS.
     * The bank rejects re-setting an already used PIN, which is harmless here.
     */
    private function bindSoftToken(): void
    {
        try {
            $this->unwrap($this->request()->withQueryParameters([
                'newPin' => $this->credential('pin'),
                'SoftToken' => $this->meta('soft_token'),
            ])->post('/AfradAuth/CreateAppPin'));
        } catch (Throwable $e) {
            Log::warning("JumhouriaDriver: CreateAppPin skipped for session {$this->session->id}", ['error' => $e->getMessage()]);
        }
    }

    /**
     * Bind the access token to the given account. Tokens are account-scoped at this bank.
     */
    private function selectAccount(string $accountNumber): void
    {
        if ($this->meta('selected_account') === $accountNumber) {
            return;
        }

        $account = $this->bankAccount($accountNumber);

        if ($account === null) {
            throw new BankApiException("Account {$accountNumber} was not found at {$this->bank->displayName()}.");
        }

        $auth = $this->unwrap($this->request()->withQueryParameters([
            'accId' => $account['accId'],
            'accountCurrencyType' => $account['currType'],
        ])->post('/AfradAuth/SignAccountAfrad'));

        $this->storeToken($auth);
        $this->rememberMeta(['selected_account' => $accountNumber]);
    }

    /** @param  array{value: string, validTo?: string}  $auth */
    private function storeToken(array $auth): void
    {
        $this->session->update([
            'access_token' => $auth['value'],
            'access_token_expires_at' => isset($auth['validTo']) ? Carbon::parse($auth['validTo']) : now()->addHours(4),
        ]);
    }

    private function customerName(): ?string
    {
        $profile = $this->unwrap($this->request()->get('/CustomerInfo/Info'));

        $this->rememberMeta([
            'customer_name' => $profile['name'] ?? null,
            'primary_account' => $profile['accountNumber'] ?? null,
            'primary_iban' => $profile['myIban'] ?? null,
        ]);

        return $profile['name'] ?? null;
    }

    /** @return list<array{accId: int, accNo: string, currType: int, branch?: string}> */
    private function bankAccounts(): array
    {
        $accounts = $this->unwrap($this->request()->get('/AfradAuth/Accounts')) ?? [];

        $this->rememberMeta(['accounts' => array_column($accounts, null, 'accNo')]);

        return $accounts;
    }

    /** @return array{accId: int, accNo: string, currType: int}|null */
    private function bankAccount(string $accountNumber): ?array
    {
        return $this->meta("accounts.{$accountNumber}") ?? array_column($this->bankAccounts(), null, 'accNo')[$accountNumber] ?? null;
    }

    private function balanceOf(string $accountNumber): ?string
    {
        try {
            $this->selectAccount($accountNumber);

            $balance = $this->unwrap($this->request()->get('/Inquiries/Balance', ['accountType' => 0]));

            return str_replace(',', '', (string) $balance);
        } catch (BankApiException $e) {
            Log::warning("JumhouriaDriver: balance unavailable for account {$accountNumber}", ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function ibanFor(string $accountNumber): string
    {
        if ($this->meta('primary_account') === $accountNumber && $this->meta('primary_iban')) {
            return $this->meta('primary_iban');
        }

        return LibyanIban::make($this->bank->config('cbl_code'), substr($accountNumber, 0, 3), $accountNumber);
    }

    /** @return list<array<string, mixed>> */
    private function friends(): array
    {
        return $this->unwrap($this->request()->get('/CrossBanksFriends/Friends')) ?? [];
    }

    /** @return array{friendName: string, friendAccountNumberOrIban: string}|null */
    private function friendFor(string $iban): ?array
    {
        $accountNumber = LibyanIban::isValid($iban) ? LibyanIban::accountNumber($iban) : $iban;

        foreach ($this->friends() as $friend) {
            $identification = LibyanIban::normalize($friend['friendAccountNumberOrIban'] ?? '');

            if ($identification === $iban || $identification === $accountNumber) {
                return $friend;
            }
        }

        return null;
    }

    /**
     * Look the beneficiary up at their bank and save them as a cross-bank friend.
     *
     * @return array{friendName: string, friendAccountNumberOrIban: string}
     */
    private function addFriend(string $iban): array
    {
        if (! LibyanIban::isValid($iban)) {
            throw new BankApiException('Please provide a valid Libyan IBAN for new beneficiaries.');
        }

        $bankCode = LibyanIban::bankCode($iban);
        $accountNumber = LibyanIban::accountNumber($iban);
        $bankName = config("banks.institutions.{$bankCode}", '');

        $lookup = $this->unwrap($this->request()->post('/CrossBanksFriends/LookUp', [
            'accountNumber' => $accountNumber,
            'bankName' => $bankName,
            'currency' => 1,
            'bankCode' => $bankCode,
        ]));

        $name = is_array($lookup)
            ? ($lookup['name'] ?? $lookup['friendName'] ?? $lookup['accountName'] ?? $lookup['customerName'] ?? null)
            : (is_string($lookup) ? $lookup : null);

        if (! is_string($name) || $name === '') {
            throw new BankApiException('Could not resolve the beneficiary name for this IBAN.');
        }

        $this->unwrap($this->request()->post('/CrossBanksFriends/NewFriend', [
            'accountNumber' => $accountNumber,
            'name' => $name,
            'bankName' => $bankName,
            'bankCode' => $bankCode,
        ]));

        return ['friendName' => $name, 'friendAccountNumberOrIban' => $accountNumber];
    }

    /**
     * Return the "content" of a successful envelope, throwing the bank's messages otherwise.
     */
    private function unwrap(Response $response): mixed
    {
        return $this->checked($response)->json('content');
    }

    private function checked(Response $response): Response
    {
        $this->throwIfFailed($response, 'messages.0');

        if ((int) $response->json('type') !== self::RESPONSE_SUCCESS) {
            $messages = array_filter((array) $response->json('messages', []));

            throw new BankApiException(
                $messages !== [] ? implode(' ', $messages) : "{$this->bank->displayName()} rejected the request.",
                $response->status(),
                $response->json(),
            );
        }

        return $response;
    }

    private function request(): PendingRequest
    {
        return $this->authenticated();
    }
}
