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
use App\Data\Bank\VoucherData;
use App\Data\Bank\VoucherDenominationData;
use App\Data\Bank\VoucherProviderData;
use App\Data\Bank\VoucherPurchaseRequestData;
use App\Data\Bank\VoucherQuoteData;
use App\Exceptions\BankApiException;
use App\Services\Banks\AbstractBankDriver;
use App\Services\Banks\Contracts\SellsVouchers;
use App\Services\Banks\Support\LibyanIban;
use Carbon\Carbon;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use Spatie\LaravelData\DataCollection;

/**
 * Driver for ATIB's online banking, a Temenos Infinity (Kony DBX) deployment.
 *
 * An anonymous app login yields a claims token that authorizes the user
 * login; the user login yields a short-lived claims token sent on every
 * request as X-Kony-Authorization. Operations are POSTs with a "jsondata="
 * form body and report failures inside an HTTP 200 through opstatus /
 * dbpErrCode. P2P (LYPAY) transfers are confirmed with an SMS secure access code.
 */
class AtibDriver extends AbstractBankDriver implements SellsVouchers
{
    private const OPERATIONS = '/services/data/v1';

    private const VOUCHER_SERVICE_CODE = '2501';

    public function credentialPrompts(): CredentialPromptsData
    {
        return new CredentialPromptsData(
            identifier_label: 'Username',
            secret_label: 'Password',
            identifier_hint: 'your ATIB online banking username',
        );
    }

    public function login(string $identifier, string $secret): LoginResultData
    {
        $this->session->update([
            'customer_id' => $identifier,
            'device_id' => $this->session->device_id ?: Str::upper((string) Str::uuid()),
            'credentials' => ['username' => $identifier, 'password' => $secret],
        ]);

        return LoginResultData::authenticated($this->authenticate());
    }

    public function verifyOtp(string $code): LoginResultData
    {
        throw new BankApiException("{$this->bank->displayName()} logins do not use an OTP.");
    }

    protected function reauthenticate(): bool
    {
        if ($this->credential('password') === null) {
            return false;
        }

        $this->authenticate();

        return true;
    }

    public function logout(): void
    {
        $this->throwIfFailed($this->authService()
            ->withHeaders(['X-Kony-Authorization' => $this->session->access_token])
            ->withQueryParameters(['provider' => 'DbxUserLogin'])
            ->post($this->authPath('logout'), ['slo' => 'false', 'provider' => 'DbxUserLogin']), 'errmsg');
    }

    public function accounts(): DataCollection
    {
        return AccountData::collect(array_map(fn (array $account) => [
            'number' => $account['accountID'],
            'available_balance' => (string) $account['availableBalance'],
            'available_balance_formatted' => $this->formatAmount($account['availableBalance'], $account['currencyCode']),
            'currency' => $account['currencyCode'],
            'description' => trim(($account['accountName'] ?? '').' · '.($account['bankName'] ?? ''), ' ·'),
            'iban' => $account['accountIBAN'] ?? null,
        ], $this->bankAccounts()), DataCollection::class);
    }

    public function transactions(string $accountNumber): DataCollection
    {
        $transactions = array_map(function (array $transaction) use ($accountNumber) {
            $isCredit = ($transaction['debitCreditIndicator'] ?? '') === 'Credit';
            $currency = $transaction['transactionCurrency'] ?? 'LYD';

            return [
                'reference' => $transaction['transactionId'],
                'type' => $isCredit ? TransactionData::TYPE_CREDIT : TransactionData::TYPE_DEBIT,
                'type_label' => $isCredit ? 'Credit' : 'Debit',
                'date' => $this->transactionDate($transaction),
                'amount' => (string) $transaction['amount'],
                'amount_formatted' => $this->formatAmount($transaction['amount'], $currency),
                'currency' => $currency,
                'code' => $transaction['transactionType'] ?? '',
                'code_description' => $transaction['description'] ?? '',
                'description' => $transaction['transactionNotes'] ?? null,
                'counterparty_account_number' => $this->counterpartyOf($transaction['transactionNotes'] ?? '', $isCredit, $accountNumber),
            ];
        }, $this->rawTransactions($accountNumber)['Transactions'] ?? []);

        return TransactionData::collect($transactions, DataCollection::class);
    }

    public function rawTransactions(string $accountNumber): mixed
    {
        return $this->operation('RBObjects/operations/Transactions/getAccountTransactionByType', [
            'accountID' => $accountNumber,
            'transactionType' => 'All',
            'offset' => 0,
            'limit' => (int) $this->bank->config('transactions_limit', 50),
            'isScheduled' => 'false',
            'order' => 'desc',
        ])->json();
    }

    public function contacts(): DataCollection
    {
        return ContactData::collect(array_map(fn (array $payee) => [
            'name' => $payee['name'],
            'identification' => $payee['iban'],
            'schema' => ContactData::SCHEMA_IBAN,
            'institution_code' => $payee['Bank_id'] ?? null,
            'institution_name' => $payee['bankName'] ?? null,
        ], $this->payees()), DataCollection::class);
    }

    public function initiateTransfer(TransferRequestData $request): TransferQuoteData
    {
        $iban = LibyanIban::normalize($request->iban);
        $payee = $this->payeeFor($iban) ?? $this->createPayee($iban);
        $account = collect($this->bankAccounts())->firstWhere('accountID', $request->debtor_account_number);

        if ($account === null) {
            throw new BankApiException("Account {$request->debtor_account_number} was not found at {$this->bank->displayName()}.");
        }

        $amount = number_format((float) $request->amount, 3, '.', '');
        $now = now()->toIso8601ZuluString('millisecond');

        $response = $this->operation('TransactionObjects/operations/Transaction/P2PTransfer', [
            'amount' => $amount,
            'beneficiaryId' => null,
            'frequencyEndDate' => $now,
            'frequencyType' => 'Once',
            'fromAccountNumber' => $account['accountID'],
            'isScheduled' => '0',
            'scheduledDate' => $now,
            'toAccountNumber' => $iban,
            'transactionsNotes' => $request->description ?? '',
            'transactionType' => 'P2P',
            'transactionCurrency' => $request->currency,
            'fromAccountCurrency' => $account['currencyCode'],
            'toAccountCurrency' => $request->currency,
            'numberOfRecurrences' => null,
            'ExternalAccountNumber' => null,
            'branch' => $payee['Bank_id'],
            'bankName' => $payee['bankName'],
            'swiftCode' => null,
            'beneficiaryName' => null,
            'personId' => $payee['PayPersonId'],
            'iban' => $account['accountIBAN'] ?? null,
            'PayeeName' => $payee['name'],
            'nickName' => $payee['nickName'] ?? $payee['name'],
            'payPersonName' => $payee['firstName'] ?? '',
            'fromNickName' => $this->meta('customer_name'),
            'toAccountType' => $payee['type'] ?? 'Retail',
            'fromAccountType' => $account['accountType'] ?? 'Checking',
            'fromBankName' => $account['bankName'] ?? '',
            'fromAccountName' => $account['accountName'] ?? '',
            'toProcessedName' => $payee['name'],
            'scheduledCalendarDate' => now()->format('m/d/Y'),
        ])->json();

        $mfa = $response['MFAAttributes'] ?? null;

        return new TransferQuoteData(
            amount_formatted: $this->formatAmount($amount, $request->currency),
            total_amount_formatted: $this->formatAmount($amount, $request->currency),
            currency: $request->currency,
            creditor_identification: $iban,
            requires_otp: $mfa !== null,
            reference: $response['referenceId'] ?? null,
            description: $request->description,
            debtor_name: $this->meta('customer_name'),
            creditor_name: $payee['name'],
            meta: [
                'security_key' => $mfa['securityKey'] ?? null,
                'service_key' => $mfa['serviceKey'] ?? null,
                'message' => $response['message'] ?? null,
            ],
        );
    }

    public function confirmTransfer(TransferQuoteData $quote, ?string $otp = null): TransferReceiptData
    {
        if (! $quote->requires_otp) {
            return new TransferReceiptData(reference: $quote->reference, message: $quote->meta['message'] ?? null);
        }

        $response = $this->operation('TransactionObjects/operations/Transaction/P2PTransfer', [
            'MFAAttributes' => [
                'serviceName' => $this->bank->config('mfa_service_name'),
                'serviceKey' => $quote->meta['service_key'],
                'OTP' => [
                    'securityKey' => $quote->meta['security_key'],
                    'otp' => $otp,
                ],
            ],
            'Action' => 'P2PTransfer',
        ]);

        return new TransferReceiptData(
            reference: $response->json('referenceId'),
            message: $response->json('message'),
        );
    }

    public function voucherProviders(): DataCollection
    {
        $providers = array_filter($this->mnoList(), fn (array $mno) => collect($mno['servicelist'] ?? [])
            ->contains(fn (array $service) => (string) $service['servicecode'] === self::VOUCHER_SERVICE_CODE));

        return VoucherProviderData::collect(array_values(array_map(fn (array $mno) => [
            'id' => (string) $mno['mnocode'],
            'name' => $mno['mnolabel'],
        ], $providers)), DataCollection::class);
    }

    public function voucherDenominations(string $providerId): DataCollection
    {
        $denominations = $this->operation('RBObjects/operations/Cards/getMNODenomination', [
            'mnocode' => $providerId,
            'servicecode' => self::VOUCHER_SERVICE_CODE,
        ])->json('denominationlist') ?? [];

        return VoucherDenominationData::collect(array_map(fn (array $denomination) => [
            'id' => (string) $denomination['denominationcode'],
            'amount' => (string) $denomination['denominationlabel'],
            'currency' => $denomination['Currency'] ?? 'LYD',
            'label' => "{$denomination['denominationlabel']} ".($denomination['Currency'] ?? 'LYD'),
        ], $denominations), DataCollection::class);
    }

    public function purchaseVoucher(VoucherPurchaseRequestData $request): VoucherQuoteData
    {
        $provider = $this->voucherProviders()->toCollection()->firstWhere('id', $request->provider_id);
        $denomination = $this->voucherDenominations($request->provider_id)->toCollection()->firstWhere('id', $request->denomination_id);

        if ($provider === null || $denomination === null) {
            throw new BankApiException('Unknown voucher provider or amount.');
        }

        $response = $this->operation('RBObjects/operations/Cards/purchaseMNO', [
            'denominationcode' => $denomination->id,
            'denominationamount' => $denomination->amount,
            'debitcurrency' => $denomination->currency,
            'mnocode' => $provider->id,
            'mnoname' => $provider->name,
            'servicename' => 'Prepaid Voucher',
            'servicecode' => self::VOUCHER_SERVICE_CODE,
            'accountId' => $request->account_number,
            'foreignAmount' => '',
            'foreignCurrency' => '',
        ])->json();

        $mfa = $response['MFAAttributes'] ?? null;

        return new VoucherQuoteData(
            account_number: $request->account_number,
            provider_id: $provider->id,
            provider_name: $provider->name,
            amount: $denomination->amount,
            amount_formatted: $this->formatAmount($denomination->amount, $denomination->currency),
            currency: $denomination->currency,
            requires_otp: $mfa !== null,
            meta: [
                'security_key' => $mfa['securityKey'] ?? null,
                'service_key' => $mfa['serviceKey'] ?? null,
                'purchase' => $mfa === null ? $response : null,
            ],
        );
    }

    public function confirmVoucherPurchase(VoucherQuoteData $quote, ?string $otp = null): VoucherData
    {
        $response = $quote->requires_otp
            ? $this->operation('RBObjects/operations/Cards/purchaseMNO', [
                'MFAAttributes' => [
                    'serviceName' => $this->bank->config('mfa_service_name'),
                    'serviceKey' => $quote->meta['service_key'],
                    'OTP' => ['securityKey' => $quote->meta['security_key'], 'otp' => $otp],
                ],
            ])->json()
            : ($quote->meta['purchase'] ?? []);

        $purchase = $response['mnoPurchase'][0] ?? null;

        if ($purchase === null || empty($purchase['pinCode'])) {
            throw new BankApiException(
                BankApiException::describe($this->bank, 200, 'the purchase response did not include a voucher code', $response),
                200,
                $response,
            );
        }

        return new VoucherData(
            provider_name: $purchase['mnoname'] ?? $quote->provider_name,
            amount: (string) ($purchase['denominationamount'] ?? $quote->amount),
            currency: $purchase['debitcurrency'] ?? $quote->currency,
            code: (string) $purchase['pinCode'],
            serial: isset($purchase['serialNum']) ? (string) $purchase['serialNum'] : null,
            reference: $response['code'] ?? null,
            purchased_at: isset($purchase['transactiondate']) ? Carbon::createFromFormat('Ymd', $purchase['transactiondate'])->toDateString() : null,
        );
    }

    /** @return list<array<string, mixed>> */
    private function mnoList(): array
    {
        return $this->operation('RBObjects/operations/Cards/getMNOServiceList')->json('mnolist') ?? [];
    }

    /**
     * Anonymous app login followed by the user login; stores the claims token and profile.
     */
    private function authenticate(): ?string
    {
        $anonymous = $this->throwIfFailed($this->authService()->post($this->authPath('login')), 'errmsg');

        $login = $this->throwIfFailed(
            $this->authService()
                ->withHeaders(['X-Kony-Authorization' => $anonymous->json('claims_token.value')])
                ->withQueryParameters(['provider' => 'DbxUserLogin'])
                ->post($this->authPath('login'), [
                    'UserName' => $this->credential('username'),
                    'Password' => $this->credential('password'),
                    'userid' => $this->credential('username'),
                    'rememberMe' => 'true',
                    'provider' => 'DbxUserLogin',
                ]),
            'errmsg'
        );

        $token = $login->json('claims_token.value');

        if (! is_string($token) || $token === '') {
            throw new BankApiException(
                BankApiException::describe($this->bank, $login->status(), 'login did not return a claims token', $login->json()),
                $login->status(),
                $login->json(),
            );
        }

        $expiresAt = $login->json('claims_token.exp');

        $this->session->update([
            'access_token' => $token,
            'access_token_expires_at' => is_numeric($expiresAt) ? Carbon::createFromTimestampMs($expiresAt) : now()->addMinutes(20),
        ]);

        $name = trim(($login->json('profile.firstname') ?? '').' '.($login->json('profile.lastname') ?? '')) ?: null;

        $this->rememberMeta(['customer_name' => $name, 'user_id' => $login->json('profile.userid')]);
        $this->rememberCustomerContract();

        return $name;
    }

    /**
     * The core-customer / contract identifiers ATIB expects when creating payees.
     */
    private function rememberCustomerContract(): void
    {
        $user = $this->throwIfFailed($this->request()->get(self::OPERATIONS.'/RBObjects/objects/User'), 'errmsg')->json('records.0') ?? [];
        $core = collect($user['CoreCustomers'] ?? [])->firstWhere('isPrimary', 'true') ?? ($user['CoreCustomers'][0] ?? []);

        $this->rememberMeta([
            'core_customer_id' => $core['coreCustomerID'] ?? null,
            'contract_id' => $core['contractId'] ?? null,
            'phone' => $user['phone'] ?? null,
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function bankAccounts(): array
    {
        return $this->operation('RBObjects/operations/Accounts/getAccountsPostLogin')->json('Accounts') ?? [];
    }

    /** @return list<array<string, mixed>> */
    private function payees(): array
    {
        return $this->operation('PayeeObjects/operations/Recipients/getP2PPayee')->json('PayPerson') ?? [];
    }

    /** @return array<string, mixed>|null */
    private function payeeFor(string $iban): ?array
    {
        foreach ($this->payees() as $payee) {
            if (LibyanIban::normalize($payee['iban'] ?? '') === $iban) {
                return $payee;
            }
        }

        return null;
    }

    /**
     * Resolve the IBAN through LYPAY and save it as a P2P payee.
     *
     * @return array<string, mixed>
     */
    private function createPayee(string $iban): array
    {
        $lookup = $this->operation('PayeeObjects/operations/Recipients/IbanLookup', ['iban' => $iban])->json('data');
        $account = $lookup['account'] ?? [];

        if (empty($account['name'])) {
            throw new BankApiException("{$this->bank->displayName()} could not resolve the IBAN {$iban}.");
        }

        $payee = [
            'name' => $account['name'],
            'nickName' => $account['name'],
            'primaryContactForSending' => '',
            'phone' => '',
            'iban' => $iban,
            'bankName' => $account['institution']['name'] ?? '',
            'type' => $account['type'] ?? 'Retail',
            'Bank_id' => $account['institution']['code'] ?? LibyanIban::bankCode($iban),
            'firstName' => $lookup['alias'] ?? '',
            'currencyCode' => '',
            'cif' => json_encode([[
                'contractId' => $this->meta('contract_id'),
                'coreCustomerId' => $this->meta('core_customer_id'),
            ]]),
        ];

        $payee['PayPersonId'] = $this->operation('PayeeObjects/operations/Recipients/createP2PPayee', $payee)->json('PayPersonId');

        return $payee;
    }

    private function transactionDate(array $transaction): string
    {
        $stamp = (string) ($transaction['dateTime'] ?? '');

        if (preg_match('/^\d{10}$/', $stamp)) {
            return Carbon::createFromFormat('ymdHi', $stamp)->toDateTimeString();
        }

        return Carbon::parse($transaction['transactionDate'])->toDateTimeString();
    }

    private function counterpartyOf(string $notes, bool $isCredit, string $ownAccount): ?string
    {
        preg_match_all('/(From|To):(\S+)/', $notes, $matches, PREG_SET_ORDER);

        foreach ($matches as [, $direction, $account]) {
            if ($account !== $ownAccount && $direction === ($isCredit ? 'From' : 'To')) {
                return $account;
            }
        }

        return null;
    }

    /**
     * Call a DBX operation and fail on any error the bank reports inside the payload.
     *
     * @param  array<string, mixed>  $data
     */
    private function operation(string $path, array $data = []): Response
    {
        $response = $this->request()
            ->withBody('jsondata='.rawurlencode(json_encode((object) $data)), 'application/json')
            ->post(self::OPERATIONS.'/'.$path);

        $this->throwIfFailed($response, 'errmsg');

        $payload = $response->json() ?? [];
        $error = match (true) {
            ! empty($payload['dbpErrCode']) => $payload['dbpErrMsg'] ?? "error {$payload['dbpErrCode']}",
            ($payload['opstatus'] ?? 0) !== 0 => $payload['errmsg'] ?? "operation status {$payload['opstatus']}",
            default => null,
        };

        if ($error !== null) {
            throw new BankApiException(
                BankApiException::describe($this->bank, $response->status(), $error, $payload),
                $response->status(),
                $payload,
            );
        }

        return $response;
    }

    protected function withAuthorization(PendingRequest $request): PendingRequest
    {
        return $request->replaceHeaders(['X-Kony-Authorization' => $this->session->access_token]);
    }

    private function request(): PendingRequest
    {
        return $this->authenticated()->withHeaders([
            'X-Kony-DeviceId' => $this->session->device_id,
            'X-Kony-API-Version' => '1.0',
            'X-Kony-ReportingParams' => $this->reportingParams(),
        ]);
    }

    private function authService(): PendingRequest
    {
        return $this->http()->asForm()->withHeaders([
            'X-Kony-App-Key' => $this->bank->config('app_key'),
            'X-Kony-App-Secret' => $this->bank->config('app_secret'),
            'X-Kony-ReportingParams' => $this->reportingParams(),
            'X-Kony-Platform-Type' => 'web',
            'X-Kony-App-Version' => '1.0',
            'X-Kony-SDK-Type' => 'js',
            'X-Kony-SDK-Version' => '9.7.52',
        ]);
    }

    private function authPath(string $action): string
    {
        return '/authService/'.$this->bank->config('auth_service_id')."/{$action}";
    }

    private function reportingParams(): string
    {
        return rawurlencode(json_encode([
            'os' => '153.0.0.0',
            'dm' => '',
            'did' => $this->session->device_id,
            'ua' => $this->bank->config('user_agent'),
            'aid' => 'OnlineBanking',
            'aname' => 'KonyOLB',
            'chnl' => 'desktop',
            'plat' => 'web',
            'aver' => '3.3.0',
            'atype' => 'spa',
            'stype' => 'b2c',
            'kuid' => $this->session->customer_id,
            'sdkversion' => '9.7.52',
            'sdktype' => 'js',
        ]));
    }
}
