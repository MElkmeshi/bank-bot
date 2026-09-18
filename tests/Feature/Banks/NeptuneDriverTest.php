<?php

use App\Enums\Bank;
use App\Exceptions\BankApiException;
use App\Models\BankSession;
use App\Services\Banks\Drivers\NeptuneDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('banks.banks.andalus.base_url', 'https://andalus.test/api/v1');
});

it('registers a device and asks for an OTP', function () {
    Http::fake([
        'firebaseinstallations.googleapis.com/*' => Http::response(['fid' => 'fid-123']),
        'andalus.test/api/v1/register' => Http::response(['data' => [
            'verification_reference' => 'ref-1',
            'customer' => ['name' => 'Ali', 'customer_id' => '42'],
        ]]),
    ]);

    $session = BankSession::factory()->create();
    $result = $session->driver()->login('42', 'secret');

    expect($result->requires_otp)->toBeTrue()
        ->and($session->fresh())
        ->device_id->toBe('fid-123')
        ->customer_id->toBe('42')
        ->verification_reference->toBe('ref-1')
        ->credentials->toBeNull();

    Http::assertSent(fn (Request $request) => $request->url() === 'https://andalus.test/api/v1/register'
        && $request['device_id'] === 'fid-123'
        && $request->header('N-Device-ID')[0] === 'fid-123');
});

it('stores tokens after verifying the OTP', function () {
    Http::fake([
        'andalus.test/api/v1/register/verify' => Http::response(['data' => [
            'access_token' => 'access',
            'refresh_token' => 'refresh',
            'access_token_expires_at' => now()->addHour()->timestamp,
            'refresh_token_expires_at' => now()->addDay()->timestamp,
            'customer' => ['name' => 'Ali', 'customer_id' => '42'],
        ]]),
    ]);

    $session = BankSession::factory()->create(['verification_reference' => 'ref-1']);
    $result = $session->driver()->verifyOtp('123456');

    expect($result->requires_otp)->toBeFalse()
        ->and($result->customer_name)->toBe('Ali')
        ->and($session->fresh())
        ->access_token->toBe('access')
        ->refresh_token->toBe('refresh')
        ->verification_reference->toBeNull()
        ->isAuthenticated()->toBeTrue();
});

it('refreshes an expired access token', function () {
    Http::fake([
        'andalus.test/api/v1/refresh-token' => Http::response(['data' => [
            'access_token' => 'fresh',
            'access_token_expires_at' => now()->addHour()->timestamp,
        ]]),
    ]);

    $session = BankSession::factory()->expired()->create();

    expect($session->driver()->ensureAuthenticated())->toBeTrue()
        ->and($session->fresh()->access_token)->toBe('fresh');

    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer refresh-token'));
});

it('maps accounts, transactions and contacts', function () {
    Http::fake([
        'andalus.test/api/v1/accounts' => Http::response(['data' => [[
            'number' => '1001',
            'description' => 'Current',
            'available_balance' => '10.500',
            'available_balance_formatted' => '10.500 LYD',
            'currency' => 'LYD',
            'currency_symbols' => 'د.ل',
            'iban' => 'LY30024007010200315020701',
        ]]]),
        'andalus.test/api/v1/accounts/1001/transactions' => Http::response(['data' => [[
            'reference' => 'TX1',
            'code' => 'TRF',
            'code_description' => 'Transfer',
            'type' => 'credit',
            'type_label' => 'Credit',
            'date' => '2026-09-18 10:00:00',
            'amount' => '5.000',
            'amount_formatted' => '5.000 LYD',
            'currency' => 'LYD',
            'currency_symbols' => 'د.ل',
            'event' => 'INIT',
            'counterparty_name' => 'Sara',
        ]]]),
        'andalus.test/api/v1/contacts' => Http::response(['data' => [[
            'id' => 1,
            'uuid' => 'u',
            'name' => 'Sara',
            'schema' => 'iban',
            'identification' => 'LY94007012012011379453011',
            'institution_code' => '007',
            'institution_name' => 'NAB',
            'type' => 'external',
            'type_label' => 'External',
        ]]]),
    ]);

    $driver = BankSession::factory()->authenticated()->create()->driver();

    expect($driver)->toBeInstanceOf(NeptuneDriver::class)
        ->and($driver->accounts()->first()->iban)->toBe('LY30024007010200315020701')
        ->and($driver->transactions('1001')->first())
        ->reference->toBe('TX1')
        ->isCredit()->toBeTrue()
        ->and($driver->contacts()->first())
        ->name->toBe('Sara')
        ->institution_code->toBe('007');
});

it('initiates and confirms a transfer with an OTP', function () {
    Http::fake([
        'andalus.test/api/v1/transfers/lypay/initiate' => Http::response(['data' => [
            'transaction_id' => 'tx-9',
            'original_amount_formatted' => '100.000 LYD',
            'total_amount_formatted' => '101.000 LYD',
            'fees_formatted' => '1.000 LYD',
            'currency' => 'LYD',
            'description' => 'rent',
            'requires_otp' => true,
            'verification_reference' => 'vref',
            'debtor' => ['name' => 'Ali', 'account_number' => '1001'],
            'creditor' => ['name' => 'Sara', 'identification' => 'LY94007012012011379453011'],
            'status' => ['code' => 'PENDING', 'description' => 'Pending'],
        ]]),
        'andalus.test/api/v1/transfers/lypay/tx-9/confirm' => Http::response(['data' => []]),
    ]);

    $session = BankSession::factory()->authenticated()->create(['customer_id' => '42']);
    $driver = $session->driver();

    $quote = $driver->initiateTransfer(new App\Data\Bank\TransferRequestData('1001', 'LY94007012012011379453011', '100', 'LYD', 'rent'));

    expect($quote->requires_otp)->toBeTrue()
        ->and($quote->reference)->toBe('tx-9')
        ->and($quote->creditor_name)->toBe('Sara');

    $receipt = $driver->confirmTransfer(App\Data\Bank\TransferQuoteData::from($quote->toArray()), '111222');

    expect($receipt->reference)->toBe('tx-9');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/tx-9/confirm')
        && $request['customer_id'] === '42'
        && $request['verification_reference'] === 'vref'
        && $request['verification_code'] === '111222');
});

it('surfaces the bank error message', function () {
    Http::fake([
        'andalus.test/api/v1/accounts' => Http::response(['message' => 'Token expired'], 401),
    ]);

    $driver = BankSession::factory()->authenticated()->create()->driver();

    expect(fn () => $driver->accounts())->toThrow(BankApiException::class, 'Token expired');
});

it('deletes the device on logout', function () {
    Http::fake(['andalus.test/api/v1/devices/delete' => Http::response([])]);

    $session = BankSession::factory()->authenticated()->create(['customer_id' => '42', 'device_id' => 'dev']);
    $session->driver()->logout();

    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
        && $request['customer_id'] === '42'
        && $request['device_id'] === 'dev');
});

it('uses the nuran configuration for nuran sessions', function () {
    config()->set('banks.banks.nuran.base_url', 'https://nuran.test/api/v1');
    Http::fake(['nuran.test/*' => Http::response(['data' => []])]);

    BankSession::factory()->forBank(Bank::Nuran)->authenticated()->create()->driver()->accounts();

    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://nuran.test/'));
});

it('reports the full bank error including field errors and code', function () {
    Http::fake([
        'andalus.test/api/v1/register' => Http::response([
            'message' => 'قد يكون رقم الزبون أو كلمة المرور غير صحيحة !.',
            'errors' => ['customer_id' => ['رقم الزبون مطلوب'], 'password' => ['كلمة المرور قصيرة']],
            'data' => [],
            'code' => 'outdated_version',
        ], 426),
        'firebaseinstallations.googleapis.com/*' => Http::response(['fid' => 'fid-123']),
    ]);

    $driver = BankSession::factory()->create()->driver();

    try {
        $driver->login('0', 'x');
        $this->fail('Expected a BankApiException');
    } catch (BankApiException $e) {
        expect($e->statusCode)->toBe(426)
            ->and($e->getMessage())->toBe(
                "Andalus Bank responded with HTTP 426: قد يكون رقم الزبون أو كلمة المرور غير صحيحة !.\n"
                ."• customer_id: رقم الزبون مطلوب\n"
                ."• password: كلمة المرور قصيرة\n"
                .'(code: outdated_version)'
            );
    }
});

it('falls back to the raw body when the bank sends no message', function () {
    Http::fake(['andalus.test/api/v1/accounts' => Http::response('<html>Bad Gateway</html>', 502)]);

    expect(fn () => BankSession::factory()->authenticated()->create()->driver()->accounts())
        ->toThrow(BankApiException::class, 'Andalus Bank responded with HTTP 502: <html>Bad Gateway</html>');
});

it('re-authenticates and retries once when the bank answers 401 before the token expired', function () {
    Http::fake([
        'andalus.test/api/v1/accounts' => Http::sequence()
            ->push(['message' => 'Unauthenticated.'], 401)
            ->push(['data' => [['number' => '1001', 'available_balance' => '1', 'available_balance_formatted' => '1 LYD', 'currency' => 'LYD']]]),
        'andalus.test/api/v1/refresh-token' => Http::response(['data' => ['access_token' => 'renewed', 'access_token_expires_at' => now()->addHour()->timestamp]]),
    ]);

    $session = BankSession::factory()->authenticated()->create();

    expect($session->driver()->accounts()->first()->number)->toBe('1001')
        ->and($session->fresh()->access_token)->toBe('renewed');

    Http::assertSentInOrder([
        fn (Request $request) => str_ends_with($request->url(), '/accounts') && $request->hasHeader('Authorization', 'Bearer access-token'),
        fn (Request $request) => str_ends_with($request->url(), '/refresh-token'),
        fn (Request $request) => str_ends_with($request->url(), '/accounts') && $request->hasHeader('Authorization', 'Bearer renewed'),
    ]);
});

it('gives up after one failed re-authentication', function () {
    Http::fake([
        'andalus.test/api/v1/accounts' => Http::response(['message' => 'Unauthenticated.'], 401),
        'andalus.test/api/v1/refresh-token' => Http::response(['message' => 'Refresh token expired'], 401),
    ]);

    expect(fn () => BankSession::factory()->authenticated()->create()->driver()->accounts())
        ->toThrow(BankApiException::class, 'HTTP 401: Unauthenticated.');

    Http::assertSentCount(2);
});
