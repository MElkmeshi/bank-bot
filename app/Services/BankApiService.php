<?php

namespace App\Services;

use App\Data\Bank\AccountData;
use App\Data\Bank\DeleteDeviceRequestData;
use App\Data\Bank\ProfileResponseData;
use App\Data\Bank\RefreshTokenResponseData;
use App\Data\Bank\RegisterRequestData;
use App\Data\Bank\RegisterResponseData;
use App\Data\Bank\TransactionData;
use App\Data\Bank\VerifyOtpRequestData;
use App\Data\Bank\VerifyOtpResponseData;
use App\Enums\Bank;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Spatie\LaravelData\DataCollection;

class BankApiService
{
    public function __construct(
        private readonly Bank $bank,
        private readonly string $deviceId,
        private readonly ?string $accessToken = null,
    ) {}

    public function register(RegisterRequestData $data): RegisterResponseData
    {
        $response = $this->client()->post('/register', $data->toArray());

        $response->throw();

        return RegisterResponseData::from($response->json('data'));
    }

    public function verifyOtp(VerifyOtpRequestData $data): VerifyOtpResponseData
    {
        $response = $this->client()->post('/register/verify', $data->toArray());

        $response->throw();

        return VerifyOtpResponseData::from($response->json('data'));
    }

    public function getProfile(): ProfileResponseData
    {
        $response = $this->client()->get('/profile');

        $response->throw();

        return ProfileResponseData::from($response->json('data'));
    }

    /** @return DataCollection<int, AccountData> */
    public function getAccounts(): DataCollection
    {
        $response = $this->client()->get('/accounts');

        $response->throw();

        return AccountData::collect($response->json('data'), DataCollection::class);
    }

    /** @return DataCollection<int, TransactionData> */
    public function getTransactions(string $accountNumber): DataCollection
    {
        $response = $this->client()->get("/accounts/{$accountNumber}/transactions");

        $response->throw();

        return TransactionData::collect($response->json('data'), DataCollection::class);
    }

    public function deleteDevice(string $customerId): void
    {
        $data = DeleteDeviceRequestData::from([
            'customer_id' => $customerId,
            'device_id' => $this->deviceId,
        ]);

        $response = $this->client()->delete('/devices/delete', $data->toArray());

        $response->throw();
    }

    public function refreshToken(string $refreshToken): RefreshTokenResponseData
    {
        $response = $this->client()
            ->withToken($refreshToken)
            ->post('/refresh-token');

        $response->throw();

        return RefreshTokenResponseData::from($response->json('data'));
    }

    private function client(): PendingRequest
    {
        $headers = [
            'Content-Type' => 'application/json',
            'N-Language' => 'ar',
            'N-Build-Number' => '135',
            'N-App-Version' => '1.0.35',
            'N-Platform' => 'ios',
            'N-Device-Model' => 'iPhone 16e',
            'N-Firebase-ID' => $this->deviceId,
            'N-Device-ID' => $this->deviceId,
        ];

        if ($this->accessToken !== null) {
            $headers['Authorization'] = "Bearer {$this->accessToken}";
        }

        return Http::baseUrl($this->bank->config('base_url'))
            ->withHeaders($headers);
    }
}
