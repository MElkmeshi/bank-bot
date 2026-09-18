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

const ATIB = 'atib-connect.atib.ly:8443';

function atibSession(array $attributes = []): BankSession
{
    return BankSession::factory()->forBank(Bank::Atib)->create(array_merge([
        'customer_id' => '1565059076',
        'device_id' => 'A35A4132-1B46-4451-9ECB-03C8841D4513',
        'access_token' => 'claims-token',
        'access_token_expires_at' => now()->addMinutes(15),
        'credentials' => ['username' => '1565059076', 'password' => 'secret'],
        'meta' => ['customer_name' => 'MOHAMED ELKMESHI', 'core_customer_id' => '10047978', 'contract_id' => '6974452694'],
    ], $attributes));
}

function atibAccount(): array
{
    return [
        'accountID' => '10000000850247', 'accountName' => 'Current accounts Retail', 'accountType' => 'Checking',
        'availableBalance' => '7000.166', 'bankName' => 'Assyahia branch فرع السياحية', 'currencyCode' => 'LYD',
        'accountIBAN' => 'LY80020072010000000850247',
    ];
}

function jsondata(Request $request): array
{
    return json_decode(rawurldecode(substr($request->body(), strlen('jsondata='))), true);
}

it('logs in through the anonymous app session and stores the claims token', function () {
    Http::fake([
        ATIB.'/authService/100000002/login' => Http::response(['claims_token' => ['value' => 'anon-token', 'exp' => now()->addMinutes(20)->getTimestampMs()]]),
        ATIB.'/authService/100000002/login?provider=DbxUserLogin' => Http::response([
            'profile' => ['userid' => '9134358882', 'firstname' => 'MOHAMED', 'lastname' => 'ELKMESHI'],
            'claims_token' => ['value' => 'user-token', 'exp' => now()->addMinutes(20)->getTimestampMs(), 'is_mfa_enabled' => false],
            'refresh_token' => 'refresh',
        ]),
        ATIB.'/services/data/v1/RBObjects/objects/User' => Http::response(['records' => [[
            'phone' => '218910441322',
            'CoreCustomers' => [['coreCustomerID' => '10047978', 'isPrimary' => 'true', 'contractId' => '6974452694']],
        ]], 'opstatus' => 0]),
    ]);

    $session = BankSession::factory()->forBank(Bank::Atib)->create(['device_id' => '']);
    $result = $session->driver()->login('1565059076', 'secret');

    expect($result->requires_otp)->toBeFalse()
        ->and($result->customer_name)->toBe('MOHAMED ELKMESHI')
        ->and($session->fresh())
        ->access_token->toBe('user-token')
        ->credentials->toBe(['username' => '1565059076', 'password' => 'secret'])
        ->meta->toMatchArray(['core_customer_id' => '10047978', 'contract_id' => '6974452694'])
        ->isAuthenticated()->toBeTrue()
        ->and($session->fresh()->device_id)->toMatch('/^[0-9A-F-]{36}$/');

    Http::assertSentInOrder([
        fn (Request $request) => $request->url() === 'https://'.ATIB.'/authService/100000002/login'
            && $request->hasHeader('X-Kony-App-Key', '32f139849b9d44f9e7625fb0f6d151eb'),
        fn (Request $request) => str_contains($request->url(), 'login?provider=DbxUserLogin')
            && $request->hasHeader('X-Kony-Authorization', 'anon-token')
            && $request['UserName'] === '1565059076' && $request['Password'] === 'secret',
        fn (Request $request) => str_ends_with($request->url(), '/objects/User') && $request->hasHeader('X-Kony-Authorization', 'user-token'),
    ]);
});

it('re-logs in with stored credentials when the claims token expired', function () {
    Http::fake([
        ATIB.'/authService/100000002/login' => Http::response(['claims_token' => ['value' => 'anon-token']]),
        ATIB.'/authService/100000002/login?provider=DbxUserLogin' => Http::response(['profile' => ['firstname' => 'M', 'lastname' => 'E'], 'claims_token' => ['value' => 'fresh']]),
        ATIB.'/services/data/v1/RBObjects/objects/User' => Http::response(['records' => [], 'opstatus' => 0]),
    ]);

    $session = atibSession(['access_token_expires_at' => now()->subMinute()]);

    expect($session->driver()->ensureAuthenticated())->toBeTrue()
        ->and($session->fresh()->access_token)->toBe('fresh');
});

it('lists accounts with balance and IBAN', function () {
    Http::fake([ATIB.'/services/data/v1/RBObjects/operations/Accounts/getAccountsPostLogin' => Http::response(['Accounts' => [atibAccount()], 'opstatus' => 0])]);

    $account = atibSession()->driver()->accounts()->first();

    expect($account->number)->toBe('10000000850247')
        ->and($account->available_balance_formatted)->toBe('7,000.166 LYD')
        ->and($account->iban)->toBe('LY80020072010000000850247');

    Http::assertSent(fn (Request $request) => $request->body() === 'jsondata='.rawurlencode('{}')
        && $request->hasHeader('X-Kony-Authorization', 'claims-token')
        && $request->hasHeader('X-Kony-DeviceId', 'A35A4132-1B46-4451-9ECB-03C8841D4513'));
});

it('maps transactions with precise times and counterparties', function () {
    Http::fake([ATIB.'/services/data/v1/RBObjects/operations/Transactions/getAccountTransactionByType' => Http::response(['Transactions' => [
        ['amount' => '1013', 'dateTime' => '2609081356', 'description' => 'LYPAY Credit Transfer', 'transactionId' => 'FT26251QRF20\\BNK', 'transactionDate' => '2026-09-08', 'transactionNotes' => 'To:10000000850247', 'transactionType' => 'Others', 'transactionCurrency' => 'LYD', 'debitCreditIndicator' => 'Credit'],
        ['amount' => '2300', 'dateTime' => '2608282012', 'description' => 'Transfer In', 'transactionId' => 'FT26242MXSGG\\BNK', 'transactionDate' => '2026-08-30', 'transactionNotes' => 'From:10000000885116 To:10000000850247', 'transactionType' => 'Others', 'transactionCurrency' => 'LYD', 'debitCreditIndicator' => 'Credit'],
        ['amount' => '300', 'dateTime' => '2609081353', 'description' => 'LYPAY Debit Transfer', 'transactionId' => 'FT26251SG8T1', 'transactionDate' => '2026-09-08', 'transactionNotes' => 'From:10000000850247', 'transactionType' => 'Others', 'transactionCurrency' => 'LYD', 'debitCreditIndicator' => 'Debit'],
    ], 'opstatus' => 0])]);

    $transactions = atibSession()->driver()->transactions('10000000850247');

    expect($transactions)->toHaveCount(3)
        ->and($transactions[0])
        ->reference->toBe('FT26251QRF20\\BNK')
        ->isCredit()->toBeTrue()
        ->date->toBe('2026-09-08 13:56:00')
        ->amount_formatted->toBe('1,013.000 LYD')
        ->counterparty_account_number->toBeNull()
        ->and($transactions[1]->counterparty_account_number)->toBe('10000000885116')
        ->and($transactions[2]->isCredit())->toBeFalse();

    Http::assertSent(fn (Request $request) => jsondata($request) === [
        'accountID' => '10000000850247', 'transactionType' => 'All', 'offset' => 0, 'limit' => 50, 'isScheduled' => 'false', 'order' => 'desc',
    ]);
});

it('maps P2P payees to contacts', function () {
    Http::fake([ATIB.'/services/data/v1/PayeeObjects/operations/Recipients/getP2PPayee' => Http::response(['PayPerson' => [
        ['name' => 'محمد', 'nickName' => 'حسابي شمال', 'PayPersonId' => '95505704', 'iban' => 'LY94007012012011379453011', 'bankName' => 'North Africa Bank', 'Bank_id' => '007', 'type' => 'Retail'],
    ], 'opstatus' => 0])]);

    $contact = atibSession()->driver()->contacts()->first();

    expect($contact->name)->toBe('محمد')
        ->and($contact->identification)->toBe('LY94007012012011379453011')
        ->and($contact->institution_code)->toBe('007');
});

it('creates a payee for a new IBAN, starts the MFA transfer and confirms it with the code', function () {
    Http::fake([
        ATIB.'/services/data/v1/PayeeObjects/operations/Recipients/getP2PPayee' => Http::response(['PayPerson' => [], 'opstatus' => 0]),
        ATIB.'/services/data/v1/PayeeObjects/operations/Recipients/IbanLookup' => Http::response(['data' => ['alias' => 'MME0072182822771', 'account' => ['number' => '012011379453011', 'institution' => ['code' => '007', 'name' => 'North Africa Bank'], 'iban' => 'LY94007012012011379453011', 'name' => 'محمد', 'type' => 'Retail']], 'opstatus' => 0]),
        ATIB.'/services/data/v1/PayeeObjects/operations/Recipients/createP2PPayee' => Http::response(['PayPersonId' => '95505704', 'opstatus' => 0]),
        ATIB.'/services/data/v1/RBObjects/operations/Accounts/getAccountsPostLogin' => Http::response(['Accounts' => [atibAccount()], 'opstatus' => 0]),
        ATIB.'/services/data/v1/TransactionObjects/operations/Transaction/P2PTransfer' => Http::sequence()
            ->push(['MFAAttributes' => ['securityKey' => 'sec-key', 'serviceKey' => 'svc-key', 'isMFARequired' => 'true'], 'success' => 'OTP request sent successfully.', 'mfaState' => 'request'])
            ->push(['referenceId' => '360600', 'opstatus' => 0, 'status' => 'Sent', 'message' => 'Success! Your transaction has been completed']),
    ]);

    $driver = atibSession()->driver();
    $quote = $driver->initiateTransfer(new TransferRequestData('10000000850247', 'LY94007012012011379453011', '1', 'LYD', 'test'));

    expect($quote->requires_otp)->toBeTrue()
        ->and($quote->creditor_name)->toBe('محمد')
        ->and($quote->amount_formatted)->toBe('1.000 LYD')
        ->and($quote->meta)->toMatchArray(['security_key' => 'sec-key', 'service_key' => 'svc-key']);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/createP2PPayee')
        && jsondata($request)['name'] === 'محمد'
        && jsondata($request)['cif'] === json_encode([['contractId' => '6974452694', 'coreCustomerId' => '10047978']]));
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/P2PTransfer') && (jsondata($request)['personId'] ?? null) === '95505704'
        && jsondata($request)['amount'] === '1.000' && jsondata($request)['iban'] === 'LY80020072010000000850247');

    $receipt = $driver->confirmTransfer(TransferQuoteData::from($quote->toArray()), '489');

    expect($receipt->reference)->toBe('360600')
        ->and($receipt->message)->toBe('Success! Your transaction has been completed');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/P2PTransfer') && jsondata($request) === [
        'MFAAttributes' => ['serviceName' => 'SERVICE_ID_67', 'serviceKey' => 'svc-key', 'OTP' => ['securityKey' => 'sec-key', 'otp' => '489']],
        'Action' => 'P2PTransfer',
    ]);
});

it('treats opstatus and dbpErrCode inside an HTTP 200 as failures', function (array $payload, string $message) {
    Http::fake([ATIB.'/services/data/v1/RBObjects/operations/Accounts/getAccountsPostLogin' => Http::response($payload)]);

    expect(fn () => atibSession()->driver()->accounts())->toThrow(BankApiException::class, $message);
})->with([
    'opstatus' => [['errmsg' => 'Error occurred while invoking Java Service.', 'opstatus' => 8004, 'httpStatusCode' => -1], 'ATIB responded with HTTP 200: Error occurred while invoking Java Service.'],
    'dbpErrCode' => [['dbpErrCode' => 12403, 'opstatus' => 0, 'dbpErrMsg' => 'SECURITY EXCEPTION - UNAUTHORIZED ACCESS'], 'SECURITY EXCEPTION - UNAUTHORIZED ACCESS'],
]);

it('logs out of the DBX session', function () {
    Http::fake([ATIB.'/authService/100000002/logout*' => Http::response(['service_doc_etag' => 'x'])]);

    atibSession()->driver()->logout();

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'logout?provider=DbxUserLogin') && $request['slo'] === 'false');
});

it('lists voucher providers and their denominations', function () {
    Http::fake([
        ATIB.'/services/data/v1/RBObjects/operations/Cards/getMNOServiceList' => Http::response(['mnolist' => [
            ['mnocode' => '30', 'mnolabel' => 'Libyana', 'servicelist' => [['servicelabel' => 'Prepaid Voucher', 'servicecode' => '2501']]],
            ['mnocode' => '31', 'mnolabel' => 'Almadar', 'servicelist' => [['servicelabel' => 'Prepaid Voucher', 'servicecode' => '2501']]],
            ['mnocode' => '99', 'mnolabel' => 'Bills only', 'servicelist' => [['servicelabel' => 'Bill', 'servicecode' => '2600']]],
        ], 'opstatus' => 0]),
        ATIB.'/services/data/v1/RBObjects/operations/Cards/getMNODenomination' => Http::response(['denominationlist' => [
            ['denominationcode' => '0310003', 'denominationlabel' => '3', 'Currency' => 'LYD'],
            ['denominationcode' => '0310005', 'denominationlabel' => '5', 'Currency' => 'LYD'],
        ], 'opstatus' => 0]),
    ]);

    $driver = atibSession()->driver();

    expect($driver->voucherProviders()->toCollection()->pluck('name')->all())->toBe(['Libyana', 'Almadar'])
        ->and($driver->voucherDenominations('31')->first())
        ->id->toBe('0310003')
        ->amount->toBe('3')
        ->label->toBe('3 LYD');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/getMNODenomination') && jsondata($request) === ['mnocode' => '31', 'servicecode' => '2501']);
});

it('buys a voucher through the MFA flow and returns the pin code', function () {
    Http::fake([
        ATIB.'/services/data/v1/RBObjects/operations/Cards/getMNOServiceList' => Http::response(['mnolist' => [
            ['mnocode' => '31', 'mnolabel' => 'Almadar', 'servicelist' => [['servicelabel' => 'Prepaid Voucher', 'servicecode' => '2501']]],
        ], 'opstatus' => 0]),
        ATIB.'/services/data/v1/RBObjects/operations/Cards/getMNODenomination' => Http::response(['denominationlist' => [
            ['denominationcode' => '0310003', 'denominationlabel' => '3', 'Currency' => 'LYD'],
        ], 'opstatus' => 0]),
        ATIB.'/services/data/v1/RBObjects/operations/Cards/purchaseMNO' => Http::sequence()
            ->push(['MFAAttributes' => ['securityKey' => 'sec', 'serviceKey' => 'svc', 'isMFARequired' => 'true'], 'success' => 'OTP request sent successfully.'])
            ->push(['code' => 'FT26263HCMNQ', 'mnoPurchase' => [['denominationamount' => '3.000', 'debitcurrency' => 'LYD', 'transactiondate' => '20260920', 'mnoname' => 'Almadar', 'pinCode' => '0899688514053', 'serialNum' => '00219938625']], 'opstatus' => 0]),
    ]);

    $driver = atibSession()->driver();
    $quote = $driver->purchaseVoucher(new App\Data\Bank\VoucherPurchaseRequestData('10000000850247', '31', '0310003'));

    expect($quote->requires_otp)->toBeTrue()
        ->and($quote->provider_name)->toBe('Almadar')
        ->and($quote->amount_formatted)->toBe('3.000 LYD');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/purchaseMNO') && jsondata($request) === [
        'denominationcode' => '0310003', 'denominationamount' => '3', 'debitcurrency' => 'LYD', 'mnocode' => '31', 'mnoname' => 'Almadar',
        'servicename' => 'Prepaid Voucher', 'servicecode' => '2501', 'accountId' => '10000000850247', 'foreignAmount' => '', 'foreignCurrency' => '',
    ]);

    $voucher = $driver->confirmVoucherPurchase(App\Data\Bank\VoucherQuoteData::from($quote->toArray()), '537');

    expect($voucher->code)->toBe('0899688514053')
        ->and($voucher->serial)->toBe('00219938625')
        ->and($voucher->reference)->toBe('FT26263HCMNQ')
        ->and($voucher->amount)->toBe('3.000')
        ->and($voucher->purchased_at)->toBe('2026-09-20');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/purchaseMNO')
        && (jsondata($request)['MFAAttributes']['OTP']['otp'] ?? null) === '537');
});

it('re-logs in and retries with the new claims token on a 401', function () {
    Http::fake([
        ATIB.'/services/data/v1/RBObjects/operations/Accounts/getAccountsPostLogin' => Http::sequence()
            ->push('', 401)
            ->push(['Accounts' => [atibAccount()], 'opstatus' => 0]),
        ATIB.'/authService/100000002/login' => Http::response(['claims_token' => ['value' => 'anon-token']]),
        ATIB.'/authService/100000002/login?provider=DbxUserLogin' => Http::response(['profile' => ['firstname' => 'M', 'lastname' => 'E'], 'claims_token' => ['value' => 'new-claims']]),
        ATIB.'/services/data/v1/RBObjects/objects/User' => Http::response(['records' => [], 'opstatus' => 0]),
    ]);

    expect(atibSession()->driver()->accounts()->first()->number)->toBe('10000000850247');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/getAccountsPostLogin') && $request->hasHeader('X-Kony-Authorization', 'new-claims'));
});
