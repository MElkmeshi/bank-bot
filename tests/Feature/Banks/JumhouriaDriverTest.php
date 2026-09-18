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

const JB = 'musrefy-plus.mitflink.ly:20888/JUMMobileChannel/api';

function envelope(mixed $content = null, int $type = 1, array $messages = []): array
{
    return ['content' => $content, 'type' => $type, 'messages' => $messages, 'traceId' => 'trace'];
}

function jbAccounts(): array
{
    return [
        ['accId' => 115310, 'accNo' => '002201000110432', 'nickName' => 'امينة', 'accState' => '1', 'accountClass' => '', 'accType' => 0, 'branch' => 'المقريف', 'currType' => 1],
        ['accId' => 17757, 'accNo' => '002331000003312', 'nickName' => 'امينة', 'accState' => '1', 'accountClass' => '', 'accType' => 0, 'branch' => 'المقريف', 'currType' => 2],
    ];
}

function jbSession(array $attributes = []): BankSession
{
    return BankSession::factory()->forBank(Bank::Jumhouria)->create(array_merge([
        'access_token' => 'auth-token',
        'access_token_expires_at' => now()->addHours(3),
        'credentials' => ['phone' => '218925941075', 'pin' => '151623'],
        'meta' => ['activated' => true, 'soft_token' => 'AEE0CF36A2C374594EAC05306C20270A', 'app_id' => 3625691],
    ], $attributes));
}

function fakeSignIn(): void
{
    Http::fake([
        'identitytoolkit.googleapis.com/*' => Http::response(['idToken' => 'firebase-id-token']),
        JB.'/AfradAuth/OpenSession' => Http::response(envelope(['appId' => 3625691, 'sessionToken' => 'session-token'])),
        JB.'/AfradAuth/SoftOtp*' => Http::response(envelope('390155')),
        JB.'/AfradAuth/Signin' => Http::response(envelope(['validTo' => now()->addHours(5)->toIso8601ZuluString(), 'refreshToken' => '', 'systemIdentity' => '1048349', 'value' => 'auth-token'])),
        JB.'/CustomerInfo/Info' => Http::response(envelope(['id' => 1048349, 'name' => 'امينة الهادى', 'accountNumber' => '002201000110432', 'myIban' => 'LY51002002002201000110432'])),
    ]);
}

it('signs in a new device and asks for the SMS activation code', function () {
    fakeSignIn();

    $session = BankSession::factory()->forBank(Bank::Jumhouria)->create(['device_id' => '']);
    $result = $session->driver()->login('218925941075', '151623');

    expect($result->requires_otp)->toBeTrue()
        ->and($session->fresh())
        ->access_token->toBe('auth-token')
        ->credentials->toBe(['phone' => '218925941075', 'pin' => '151623'])
        ->meta->toHaveKeys(['soft_token', 'app_id'])
        ->and($session->fresh()->meta['soft_token'])->toHaveLength(32);

    Http::assertSentInOrder([
        fn (Request $request) => str_contains($request->url(), 'identitytoolkit'),
        fn (Request $request) => str_ends_with($request->url(), '/AfradAuth/OpenSession') && $request->isMultipart(),
        fn (Request $request) => str_contains($request->url(), '/AfradAuth/SoftOtp?softToken=') && $request->hasHeader('Authorization', 'Bearer session-token'),
        fn (Request $request) => str_ends_with($request->url(), '/AfradAuth/Signin') && $request['pin'] === '151623' && $request['otp'] === '390155',
    ]);
});

it('activates the device with the SMS code and binds the soft token', function () {
    Http::fake([
        JB.'/AfradAuth/Activate*' => Http::response(envelope()),
        JB.'/AfradAuth/CreateAppPin*' => Http::response(envelope(null, 2, ['لايمكن تعيين كلمة مرور مستخدمة مسبقا'])),
        JB.'/CustomerInfo/Info' => Http::response(envelope(['name' => 'امينة', 'accountNumber' => '002201000110432', 'myIban' => 'LY51002002002201000110432'])),
    ]);

    $session = jbSession(['meta' => ['activated' => false, 'soft_token' => 'SOFT']]);
    $result = $session->driver()->verifyOtp('980126');

    expect($result->requires_otp)->toBeFalse()
        ->and($result->customer_name)->toBe('امينة')
        ->and($session->fresh()->meta)->toMatchArray(['activated' => true, 'primary_iban' => 'LY51002002002201000110432']);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'Activate?activeCode=980126'));
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'CreateAppPin?newPin=151623&SoftToken=SOFT'));
});

it('silently signs in again once the token expired', function () {
    fakeSignIn();

    $session = jbSession(['access_token' => 'old', 'access_token_expires_at' => now()->subMinute()]);

    expect($session->driver()->ensureAuthenticated())->toBeTrue()
        ->and($session->fresh()->access_token)->toBe('auth-token');

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'Activate'));
});

it('lists accounts with balances by signing into each account', function () {
    Http::fake([
        JB.'/AfradAuth/Accounts' => Http::response(envelope(jbAccounts())),
        JB.'/AfradAuth/SignAccountAfrad*' => Http::response(envelope(['validTo' => now()->addHours(5)->toIso8601ZuluString(), 'value' => 'scoped-token'])),
        JB.'/Inquiries/Balance*' => Http::sequence()
            ->push(envelope('3,983.654'))
            ->push(envelope(null, 3, ['حدثت مشكلة في الخادم'])),
    ]);

    $accounts = jbSession()->driver()->accounts();

    expect($accounts)->toHaveCount(2)
        ->and($accounts[0])
        ->number->toBe('002201000110432')
        ->currency->toBe('LYD')
        ->available_balance->toBe('3983.654')
        ->available_balance_formatted->toBe('3,983.654 LYD')
        ->iban->toBe('LY51002002002201000110432')
        ->and($accounts[1])
        ->currency->toBe('USD')
        ->available_balance_formatted->toBe('unavailable');

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'SignAccountAfrad?accId=115310&accountCurrencyType=1'));
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'SignAccountAfrad?accId=17757&accountCurrencyType=2'));
});

it('maps the last statement to transactions with stable synthetic references', function () {
    Http::fake([
        JB.'/AfradAuth/Accounts' => Http::response(envelope(jbAccounts())),
        JB.'/AfradAuth/SignAccountAfrad*' => Http::response(envelope(['value' => 'scoped-token'])),
        JB.'/Inquiries/LastAccountStatement*' => Http::response(envelope(['bankStatements' => [
            ['descrption' => 'شراء كروت ليبيانا', 'amount' => '5', 'postDate' => '20/09/2026', 'type' => 2, 'typeStr' => 'D', 'availableBalance' => null, 'sequenceNumber' => null],
            ['descrption' => 'شراء كروت ليبيانا', 'amount' => '5', 'postDate' => '20/09/2026', 'type' => 2, 'typeStr' => 'D', 'availableBalance' => null, 'sequenceNumber' => null],
            ['descrption' => 'حوالة واردة', 'amount' => '100', 'postDate' => '15/09/2026', 'type' => 1, 'typeStr' => 'C', 'availableBalance' => null, 'sequenceNumber' => null],
        ], 'state' => 0, 'message' => null])),
    ]);

    $transactions = jbSession()->driver()->transactions('002201000110432');
    $again = jbSession()->driver()->transactions('002201000110432');

    expect($transactions)->toHaveCount(3)
        ->and($transactions[0]->reference)->not->toBe($transactions[1]->reference)
        ->and($transactions[0]->reference)->toBe($again[0]->reference)
        ->and($transactions[0])
        ->isCredit()->toBeFalse()
        ->date->toBe('2026-09-20 00:00:00')
        ->amount_formatted->toBe('5.000 LYD')
        ->and($transactions[2])
        ->isCredit()->toBeTrue()
        ->code_description->toBe('حوالة واردة');
});

it('maps cross-bank friends to contacts', function () {
    Http::fake([JB.'/CrossBanksFriends/Friends' => Http::response(envelope([
        ['friendId' => 1, 'friendAccountNumberOrIban' => '012011379453011', 'friendName' => 'محمد', 'bankName' => 'شمال أفريقيا', 'friendCblBankCode' => '007', 'friendCurrencyType' => 1],
    ]))]);

    $contact = jbSession()->driver()->contacts()->first();

    expect($contact->name)->toBe('محمد')
        ->and($contact->identification)->toBe('012011379453011')
        ->and($contact->schema)->toBe('account')
        ->and($contact->institution_code)->toBe('007');
});

it('adds an unknown IBAN as a friend, requests the SMS OTP and sends the transfer', function () {
    Http::fake([
        JB.'/CrossBanksFriends/Friends' => Http::response(envelope([])),
        JB.'/CrossBanksFriends/LookUp' => Http::response(envelope(['name' => 'محمد الكميشي'])),
        JB.'/CrossBanksFriends/NewFriend' => Http::response(envelope(['friendId' => 16186304, 'friendName' => 'محمد الكميشي', 'friendAccountNumber' => '012011379453011'])),
        JB.'/AfradAuth/Accounts' => Http::response(envelope(jbAccounts())),
        JB.'/AfradAuth/SignAccountAfrad*' => Http::response(envelope(['value' => 'scoped-token'])),
        JB.'/AfradAuth/ResendActiveCode*' => Http::response(envelope()),
        JB.'/CustomerInfo/Info' => Http::response(envelope(['name' => 'امينة', 'accountNumber' => '002201000110432', 'myIban' => 'LY51002002002201000110432'])),
        JB.'/Transactions/CrossBankMoneyTransaction' => Http::response(envelope(null, 1, [' تم قبول عملية تحويل أموال رقم 002MT0028668709 خدمة OnePay'])),
    ]);

    $driver = jbSession()->driver();
    $quote = $driver->initiateTransfer(new TransferRequestData('002201000110432', 'LY94007012012011379453011', '100', 'LYD', 'test'));

    expect($quote->requires_otp)->toBeTrue()
        ->and($quote->creditor_name)->toBe('محمد الكميشي')
        ->and($quote->meta['from_iban'])->toBe('LY51002002002201000110432');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/CrossBanksFriends/LookUp')
        && $request['accountNumber'] === '012011379453011' && $request['bankCode'] === '007');
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/CrossBanksFriends/NewFriend') && $request['name'] === 'محمد الكميشي');
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'ResendActiveCode?otpType=0'));

    $receipt = $driver->confirmTransfer(TransferQuoteData::from($quote->toArray()), '738680');

    expect($receipt->reference)->toBe('002MT0028668709');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/Transactions/CrossBankMoneyTransaction')
        && $request['fromIban'] === 'LY51002002002201000110432'
        && $request['toIban'] === 'LY94007012012011379453011'
        && $request['amount'] === 100.0
        && $request['otp'] === '738680'
        && $request['crossBankWay'] === 2);
});

it('reuses an existing friend instead of looking the IBAN up', function () {
    Http::fake([
        JB.'/CrossBanksFriends/Friends' => Http::response(envelope([
            ['friendAccountNumberOrIban' => '012011379453011', 'friendName' => 'محمد', 'friendCblBankCode' => '007'],
        ])),
        JB.'/AfradAuth/Accounts' => Http::response(envelope(jbAccounts())),
        JB.'/AfradAuth/SignAccountAfrad*' => Http::response(envelope(['value' => 'scoped-token'])),
        JB.'/AfradAuth/ResendActiveCode*' => Http::response(envelope()),
        JB.'/CustomerInfo/Info' => Http::response(envelope(['name' => 'امينة'])),
    ]);

    $quote = jbSession()->driver()->initiateTransfer(new TransferRequestData('002201000110432', 'LY94007012012011379453011', '10'));

    expect($quote->creditor_name)->toBe('محمد');

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'LookUp'));
});

it('turns envelope errors into bank exceptions', function () {
    Http::fake([JB.'/CrossBanksFriends/Friends' => Http::response(envelope(null, 2, ['معلومات الحساب غير صحيحة']))]);

    expect(fn () => jbSession()->driver()->contacts())->toThrow(BankApiException::class, 'معلومات الحساب غير صحيحة');
});

it('lists voucher providers, requests the SMS OTP and buys a voucher', function () {
    Http::fake([
        JB.'/Lists/Vouchers*' => Http::response(envelope([
            ['providerName' => 'المدار', 'providerId' => '31', 'vouchers' => ['5', '10', '20']],
            ['providerName' => 'ليبيانا', 'providerId' => '32', 'vouchers' => ['5', '10', '30']],
        ])),
        JB.'/AfradAuth/Accounts' => Http::response(envelope(jbAccounts())),
        JB.'/AfradAuth/SignAccountAfrad*' => Http::response(envelope(['value' => 'scoped-token'])),
        JB.'/AfradAuth/ResendActiveCode*' => Http::response(envelope()),
        JB.'/Transactions/BVTransaction' => Http::response(envelope(null, 1, ['تمت العملية بنجاح'])),
        JB.'/Reporting/Vouchers*' => Http::response(envelope(['pageContent' => [
            ['sequenceNumber' => 102741507, 'providerId' => 32, 'cardValue' => 5, 'cardSerial' => '535742257451336', 'cardSecret' => '9949752836112', 'creationTime' => '2026-09-18T14:15:25'],
            ['sequenceNumber' => 102522280, 'providerId' => 32, 'cardValue' => 5, 'cardSerial' => '535742257419438', 'cardSecret' => '9959485898864', 'creationTime' => '2026-09-16T12:03:21'],
            ['sequenceNumber' => 99728108, 'providerId' => 31, 'cardValue' => 10, 'cardSerial' => '1', 'cardSecret' => '2', 'creationTime' => '2026-09-17T00:00:00'],
        ]])),
    ]);

    $driver = jbSession()->driver();

    expect($driver->voucherProviders()->toCollection()->pluck('name')->all())->toBe(['المدار', 'ليبيانا'])
        ->and($driver->voucherDenominations('32')->toCollection()->pluck('amount')->all())->toBe(['5', '10', '30']);

    $quote = $driver->purchaseVoucher(new App\Data\Bank\VoucherPurchaseRequestData('002201000110432', '32', '5'));

    expect($quote->requires_otp)->toBeTrue()->and($quote->provider_name)->toBe('ليبيانا');

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'ResendActiveCode?otpType=0'));

    $voucher = $driver->confirmVoucherPurchase(App\Data\Bank\VoucherQuoteData::from($quote->toArray()), '359867');

    expect($voucher->code)->toBe('9949752836112')
        ->and($voucher->serial)->toBe('535742257451336')
        ->and($voucher->reference)->toBe('102741507');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/Transactions/BVTransaction')
        && $request['providerId'] === '32' && $request['cardValue'] === '5' && $request['otp'] === '359867' && $request['accountType'] === 0);
});
