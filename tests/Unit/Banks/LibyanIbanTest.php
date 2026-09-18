<?php

use App\Services\Banks\Support\LibyanIban;

it('builds a valid Libyan IBAN from bank, branch and account', function (string $bank, string $branch, string $account, string $expected) {
    expect(LibyanIban::make($bank, $branch, $account))->toBe($expected);
})->with([
    'jumhouria' => ['002', '002', '002201000110432', 'LY51002002002201000110432'],
    'north africa' => ['007', '012', '012011379453011', 'LY94007012012011379453011'],
    'nuran' => ['024', '007', '010200315020701', 'LY30024007010200315020701'],
]);

it('validates IBAN check digits', function () {
    expect(LibyanIban::isValid('LY30024007010200315020701'))->toBeTrue()
        ->and(LibyanIban::isValid('ly30 0240 0701 0200 3150 2070 1'))->toBeTrue()
        ->and(LibyanIban::isValid('LY31024007010200315020701'))->toBeFalse()
        ->and(LibyanIban::isValid('012011379453011'))->toBeFalse();
});

it('extracts the parts of an IBAN', function () {
    expect(LibyanIban::bankCode('LY80020072010000000850247'))->toBe('020')
        ->and(LibyanIban::branchCode('LY80020072010000000850247'))->toBe('072')
        ->and(LibyanIban::accountNumber('LY80020072010000000850247'))->toBe('010000000850247');
});
