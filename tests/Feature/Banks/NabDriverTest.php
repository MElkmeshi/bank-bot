<?php

use App\Data\Bank\TransferQuoteData;
use App\Data\Bank\TransferRequestData;
use App\Enums\Bank;
use App\Exceptions\BankApiException;
use App\Models\BankSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function nabToken(array $claims = []): string
{
    $payload = base64_encode(json_encode(array_merge([
        'accountNumber' => '012011379453011',
        'fullName' => 'محمد الكميشي',
        'branchName' => 'وكالة صلاح الدين',
        'iban' => 'LY94007012012011379453011',
    ], $claims)));

    return 'eyJhbGciOiJIUzI1NiJ9.'.rtrim(strtr($payload, '+/', '-_'), '=').'.sig';
}

function nabSession(array $attributes = []): BankSession
{
    return BankSession::factory()->forBank(Bank::Nab)->create(array_merge([
        'access_token' => nabToken(),
        'access_token_expires_at' => now()->addMinutes(20),
        'meta' => ['accountNumber' => '012011379453011', 'iban' => 'LY94007012012011379453011', 'fullName' => 'محمد الكميشي', 'branchName' => 'Branch'],
    ], $attributes));
}

it('logs in without an OTP and remembers the account claims', function () {
    Http::fake(['nabmobile.nab.ly/api/identity/login' => Http::response([
        'accessToken' => nabToken(), 'deviceKey' => null, 'expiresIn' => 1799,
    ])]);

    $session = BankSession::factory()->forBank(Bank::Nab)->create(['device_id' => '']);
    $result = $session->driver()->login('927366649', 'pass');

    expect($result->requires_otp)->toBeFalse()
        ->and($result->customer_name)->toBe('محمد الكميشي')
        ->and($session->fresh())
        ->device_id->toHaveLength(32)
        ->credentials->toBe(['phone' => '927366649', 'password' => 'pass'])
        ->meta->toMatchArray(['accountNumber' => '012011379453011', 'iban' => 'LY94007012012011379453011'])
        ->isAuthenticated()->toBeTrue();

    Http::assertSent(fn (Request $request) => $request['phoneNumber'] === '927366649'
        && strlen($request['deviceKey']) === 32
        && $request->header('User-Agent')[0] === 'Dart/3.10 (dart:io)');
});

it('re-logs in with stored credentials when the token expired', function () {
    Http::fake(['nabmobile.nab.ly/api/identity/login' => Http::response(['accessToken' => nabToken(), 'expiresIn' => 1799])]);

    $session = nabSession([
        'access_token_expires_at' => now()->subMinute(),
        'credentials' => ['phone' => '927366649', 'password' => 'pass'],
    ]);

    expect($session->driver()->ensureAuthenticated())->toBeTrue()
        ->and($session->fresh()->isAuthenticated())->toBeTrue();
});

it('cannot re-login without stored credentials', function () {
    Http::fake();

    expect(nabSession(['access_token_expires_at' => now()->subMinute()])->driver()->ensureAuthenticated())->toBeFalse();

    Http::assertNothingSent();
});

it('exposes the single account with its balance', function () {
    Http::fake(['nabmobile.nab.ly/api/balance' => Http::response(['balance' => 595.83])]);

    $account = nabSession()->driver()->accounts()->first();

    expect($account->number)->toBe('012011379453011')
        ->and($account->iban)->toBe('LY94007012012011379453011')
        ->and($account->available_balance_formatted)->toBe('595.830 LYD');
});

it('maps the statement to transactions', function () {
    Http::fake(['nabmobile.nab.ly/api/statement*' => Http::response([
        'accountNumber' => '012011379453011',
        'transactions' => [
            ['desc' => null, 'trnCdDesc' => 'تحويل من حساب', 'amount' => 500, 'runnBal' => 600.83, 'transactionType' => 'C', 'transactionDt' => '2026-07-02 00:00', 'trnRef' => '012OPCT261831692'],
            ['desc' => 'شحن محفظة', 'trnCdDesc' => 'Etravle', 'amount' => 10, 'runnBal' => 0.83, 'transactionType' => 'D', 'transactionDt' => '2026-07-01 00:00', 'trnRef' => 'ETRVL13794530ZMW'],
        ],
    ])]);

    $transactions = nabSession()->driver()->transactions('012011379453011');

    expect($transactions)->toHaveCount(2)
        ->and($transactions[0])
        ->reference->toBe('012OPCT261831692')
        ->isCredit()->toBeTrue()
        ->amount_formatted->toBe('500.000 LYD')
        ->code_description->toBe('تحويل من حساب')
        ->and($transactions[1])
        ->isCredit()->toBeFalse()
        ->description->toBe('شحن محفظة');

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'fromDate='.now()->subDays(7)->toDateString()));
});

it('maps the IPS whitelist to contacts', function () {
    Http::fake(['nabmobile.nab.ly/api/ips/whitelist' => Http::response([[
        'id' => 796710, 'creditorIban' => 'LY80020072010000000850247', 'creditorAlias' => 'MME', 'creditorName' => 'MOHAMED', 'creditorType' => 'P', 'creditorInstitution' => 'مصرف السراي', 'creditorInstitutionId' => '020',
    ]])]);

    $contact = nabSession()->driver()->contacts()->first();

    expect($contact->name)->toBe('MOHAMED')
        ->and($contact->identification)->toBe('LY80020072010000000850247')
        ->and($contact->institution_code)->toBe('020');
});

it('whitelists a new IBAN, requests an OTP and sends the transfer', function () {
    Http::fake([
        'nabmobile.nab.ly/api/ips/whitelist' => Http::response([]),
        'nabmobile.nab.ly/api/ips/whitelist/verify' => Http::response(['creditorName' => 'MOHAMED', 'creditorInstitution' => 'مصرف السراي']),
        'nabmobile.nab.ly/api/ips/whitelist/active' => Http::response(''),
        'nabmobile.nab.ly/api/Otp/Request' => Http::response([]),
        'nabmobile.nab.ly/api/ips' => Http::response(['id' => 'ips-1']),
    ]);

    $driver = nabSession()->driver();
    $quote = $driver->initiateTransfer(new TransferRequestData('012011379453011', 'ly80 0200 7201 0000 0008 5024 7', '1.5', 'LYD', 'gift'));

    expect($quote->requires_otp)->toBeTrue()
        ->and($quote->creditor_name)->toBe('MOHAMED')
        ->and($quote->creditor_identification)->toBe('LY80020072010000000850247')
        ->and($quote->meta['bank'])->toBe('020');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/whitelist/verify') && $request['identification'] === 'LY80020072010000000850247');
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/Otp/Request'));

    $receipt = $driver->confirmTransfer(TransferQuoteData::from($quote->toArray()), '123456');

    expect($receipt->reference)->toBe('ips-1');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/api/ips')
        && $request->method() === 'POST'
        && $request['amount'] === 1.5
        && $request['bank'] === '020'
        && $request['transactionType'] === 'P2P'
        && $request['otp'] === '123456');
});

it('skips whitelisting for known contacts', function () {
    Http::fake([
        'nabmobile.nab.ly/api/ips/whitelist' => Http::response([['creditorIban' => 'LY80020072010000000850247', 'creditorName' => 'MOHAMED']]),
        'nabmobile.nab.ly/api/Otp/Request' => Http::response([]),
    ]);

    nabSession()->driver()->initiateTransfer(new TransferRequestData('012011379453011', 'LY80020072010000000850247', '1'));

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/whitelist/verify'));
});

it('surfaces the bank error message on a wrong OTP', function () {
    Http::fake(['nabmobile.nab.ly/api/ips' => Http::response(['errorCode' => '', 'errorMessage' => 'رقم التحقق الذي ادخلته غير صحيح'], 400)]);

    $quote = new TransferQuoteData('1.000 LYD', '1.000 LYD', 'LYD', 'LY80020072010000000850247', true, meta: ['amount' => '1', 'bank' => '020']);

    expect(fn () => nabSession()->driver()->confirmTransfer($quote, '000000'))
        ->toThrow(BankApiException::class, 'رقم التحقق الذي ادخلته غير صحيح');
});

it('lists voucher types and buys a voucher without an OTP', function () {
    Http::fake([
        'nabmobile.nab.ly/api/vouchers/types' => Http::response([
            ['id' => 1, 'nameAr' => 'ليبيانا', 'categories' => [['id' => 1, 'description' => 'كرت 3', 'price' => 3], ['id' => 3, 'description' => 'كرت 10', 'price' => 10]]],
            ['id' => 2, 'nameAr' => 'المدار', 'categories' => [['id' => 7, 'description' => 'كرت 5', 'price' => 5]]],
        ]),
        'nabmobile.nab.ly/api/vouchers' => Http::response(['iconLink' => 'almadar.png', 'price' => 5, 'description' => 'المدار', 'voucherCode' => '8732317956079', 'boughtAt' => '2026-07-04 14:39']),
    ]);

    $driver = nabSession()->driver();

    expect($driver->voucherProviders()->toCollection()->pluck('name')->all())->toBe(['ليبيانا', 'المدار'])
        ->and($driver->voucherDenominations('2')->first()->label)->toBe('كرت 5');

    $quote = $driver->purchaseVoucher(new App\Data\Bank\VoucherPurchaseRequestData('012011379453011', '2', '7'));

    expect($quote->requires_otp)->toBeFalse()
        ->and($quote->amount_formatted)->toBe('5.000 LYD');

    $voucher = $driver->confirmVoucherPurchase($quote);

    expect($voucher->code)->toBe('8732317956079')
        ->and($voucher->provider_name)->toBe('المدار')
        ->and($voucher->purchased_at)->toBe('2026-07-04 14:39');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/api/vouchers') && $request->method() === 'POST' && $request['voucherCategoryId'] === 7);
});
