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
use App\Models\BankSession;
use App\Services\Banks\AbstractBankDriver;
use App\Services\FirebaseService;
use Carbon\Carbon;
use Illuminate\Http\Client\PendingRequest;
use Spatie\LaravelData\DataCollection;

/**
 * Driver for the mobile-banking platform shared by Andalus Bank and Nuran Bank.
 *
 * Devices are identified by a Firebase installation ID, logins are confirmed
 * by SMS OTP and sessions are kept alive with a refresh token.
 */
class NeptuneDriver extends AbstractBankDriver
{
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
            identifier_label: 'Customer ID',
            secret_label: 'Password',
        );
    }

    public function login(string $identifier, string $secret): LoginResultData
    {
        $deviceId = $this->firebase->getInstallationId($this->bank);

        $response = $this->request($this->http(), $deviceId)->post('/register', [
            'customer_id' => $identifier,
            'device_id' => $deviceId,
            'password' => $secret,
        ]);

        $this->throwIfFailed($response, 'message');

        $this->session->update([
            'customer_id' => $identifier,
            'device_id' => $deviceId,
            'verification_reference' => $response->json('data.verification_reference'),
            'credentials' => null,
        ]);
        $this->session->clearTokens();

        return LoginResultData::pendingOtp('An OTP has been sent to your phone. Please enter the verification code:');
    }

    public function verifyOtp(string $code): LoginResultData
    {
        $response = $this->request($this->http())->post('/register/verify', [
            'verification_reference' => $this->session->verification_reference,
            'customer_id' => $this->session->customer_id,
            'verification_code' => $code,
        ]);

        $this->throwIfFailed($response, 'message');

        $this->session->update([
            'access_token' => $response->json('data.access_token'),
            'refresh_token' => $response->json('data.refresh_token'),
            'access_token_expires_at' => Carbon::createFromTimestamp($response->json('data.access_token_expires_at')),
            'refresh_token_expires_at' => Carbon::createFromTimestamp($response->json('data.refresh_token_expires_at')),
            'verification_reference' => null,
        ]);

        return LoginResultData::authenticated($response->json('data.customer.name'));
    }

    protected function reauthenticate(): bool
    {
        if (empty($this->session->refresh_token)) {
            return false;
        }

        $response = $this->request($this->http())
            ->withToken($this->session->refresh_token)
            ->post('/refresh-token');

        $this->throwIfFailed($response, 'message');

        $this->session->update([
            'access_token' => $response->json('data.access_token'),
            'access_token_expires_at' => Carbon::createFromTimestamp($response->json('data.access_token_expires_at')),
        ]);

        return true;
    }

    public function logout(): void
    {
        $response = $this->request($this->authenticated())->delete('/devices/delete', [
            'customer_id' => $this->session->customer_id,
            'device_id' => $this->session->device_id,
        ]);

        $this->throwIfFailed($response, 'message');
    }

    public function accounts(): DataCollection
    {
        $response = $this->throwIfFailed($this->request($this->authenticated())->get('/accounts'), 'message');

        return AccountData::collect($response->json('data'), DataCollection::class);
    }

    public function transactions(string $accountNumber): DataCollection
    {
        return TransactionData::collect($this->rawTransactions($accountNumber)['data'] ?? [], DataCollection::class);
    }

    public function rawTransactions(string $accountNumber): mixed
    {
        return $this->throwIfFailed(
            $this->request($this->authenticated())->get("/accounts/{$accountNumber}/transactions"),
            'message'
        )->json();
    }

    public function contacts(): DataCollection
    {
        $response = $this->throwIfFailed($this->request($this->authenticated())->get('/contacts'), 'message');

        return ContactData::collect(
            array_map(fn (array $contact) => [
                'name' => $contact['name'],
                'identification' => $contact['identification'],
                'schema' => $contact['schema'] ?? ContactData::SCHEMA_IBAN,
                'institution_code' => $contact['institution_code'] ?? null,
                'institution_name' => $contact['institution_name'] ?? null,
            ], $response->json('data') ?? []),
            DataCollection::class
        );
    }

    public function initiateTransfer(TransferRequestData $request): TransferQuoteData
    {
        $response = $this->request($this->authenticated())->post('/transfers/lypay/initiate', [
            'debtor_account_number' => $request->debtor_account_number,
            'identification' => $request->iban,
            'schema' => 'iban',
            'amount' => $request->amount,
            'currency' => $request->currency,
            'description' => $request->description,
        ]);

        $this->throwIfFailed($response, 'message');

        return new TransferQuoteData(
            amount_formatted: $response->json('data.original_amount_formatted'),
            total_amount_formatted: $response->json('data.total_amount_formatted'),
            currency: $response->json('data.currency'),
            creditor_identification: $response->json('data.creditor.identification') ?? $request->iban,
            requires_otp: (bool) $response->json('data.requires_otp'),
            reference: $response->json('data.transaction_id'),
            fees_formatted: $response->json('data.fees_formatted'),
            description: $response->json('data.description'),
            debtor_name: $response->json('data.debtor.name'),
            creditor_name: $response->json('data.creditor.name'),
            meta: ['verification_reference' => $response->json('data.verification_reference')],
        );
    }

    public function confirmTransfer(TransferQuoteData $quote, ?string $otp = null): TransferReceiptData
    {
        $body = [];

        if ($otp !== null) {
            $body = [
                'customer_id' => $this->session->customer_id,
                'verification_reference' => $quote->meta['verification_reference'] ?? null,
                'verification_code' => $otp,
            ];
        }

        $this->throwIfFailed(
            $this->request($this->authenticated())->post("/transfers/lypay/{$quote->reference}/confirm", $body),
            'message'
        );

        return new TransferReceiptData(reference: $quote->reference);
    }

    private function request(PendingRequest $client, ?string $deviceId = null): PendingRequest
    {
        $deviceId ??= $this->session->device_id;

        return $client->withHeaders([
            'Content-Type' => 'application/json',
            'N-Language' => 'ar',
            'N-Build-Number' => $this->bank->config('build_number'),
            'N-App-Version' => $this->bank->config('app_version'),
            'N-Platform' => 'ios',
            'N-Device-Model' => 'Telegram Bot',
            'N-Firebase-ID' => $deviceId,
            'N-Device-ID' => $deviceId,
        ]);
    }
}
