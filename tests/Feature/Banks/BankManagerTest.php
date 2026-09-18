<?php

use App\Enums\Bank;
use App\Models\BankSession;
use App\Services\Banks\BankManager;
use App\Services\Banks\Contracts\BankDriver;
use App\Services\Banks\Drivers\AtibDriver;
use App\Services\Banks\Drivers\JumhouriaDriver;
use App\Services\Banks\Drivers\NabDriver;
use App\Services\Banks\Drivers\NeptuneDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('resolves the configured driver for every bank', function (Bank $bank, string $driver) {
    $session = BankSession::factory()->forBank($bank)->create();

    expect($session->driver())->toBeInstanceOf($driver)
        ->and($session->driver()->bank())->toBe($bank);
})->with([
    'andalus' => [Bank::Andalus, NeptuneDriver::class],
    'nuran' => [Bank::Nuran, NeptuneDriver::class],
    'jumhouria' => [Bank::Jumhouria, JumhouriaDriver::class],
    'nab' => [Bank::Nab, NabDriver::class],
    'atib' => [Bank::Atib, AtibDriver::class],
]);

it('allows registering custom drivers', function () {
    config()->set('banks.banks.nab.driver', 'fake');

    $fake = Mockery::mock(BankDriver::class);

    app(BankManager::class)->extend('fake', fn () => $fake);

    $session = BankSession::factory()->forBank(Bank::Nab)->create();

    expect($session->driver())->toBe($fake);
});

it('stores credentials encrypted', function () {
    $session = BankSession::factory()->create(['credentials' => ['password' => 'secret']]);

    expect($session->fresh()->credentials)->toBe(['password' => 'secret'])
        ->and($session->getRawOriginal('credentials'))->not->toContain('secret');
});
