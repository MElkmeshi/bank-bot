<?php

use App\Enums\Bank;
use App\Models\BankRequest;
use App\Models\BankSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('banks.banks.andalus.base_url', 'https://andalus.test/api/v1');
});

it('records every request a driver makes, with secrets redacted and bodies encrypted', function () {
    Http::fake([
        'firebaseinstallations.googleapis.com/*' => Http::response(['fid' => 'fid-123']),
        'andalus.test/api/v1/register' => Http::response(['data' => ['verification_reference' => 'ref-1']]),
    ]);

    $session = BankSession::factory()->create();
    $session->driver()->login('42', 'super-secret');

    expect(BankRequest::count())->toBe(2);

    $firebase = BankRequest::where('url', 'like', 'https://firebaseinstallations%')->first();
    $register = BankRequest::where('url', 'https://andalus.test/api/v1/register')->first();

    expect($firebase)
        ->bank->toBe(Bank::Andalus)
        ->bank_session_id->toBeNull()
        ->status->toBe(200)
        ->and($firebase->request_headers['x-goog-api-key'])->toBe('[redacted]')
        ->and($register)
        ->bank_session_id->toBe($session->id)
        ->method->toBe('POST')
        ->status->toBe(200)
        ->error->toBeNull()
        ->and($register->request_body)->toContain('"customer_id":"42"')
        ->and($register->request_body)->toContain('"password":"[redacted]"')
        ->and($register->request_body)->not->toContain('super-secret')
        ->and($register->getRawOriginal('request_body'))->not->toContain('customer_id')
        ->and($register->response_body)->toBe('{"data":{"verification_reference":"ref-1"}}')
        ->and($register->duration_ms)->toBeGreaterThanOrEqual(0);
});

it('records failed responses and connection errors', function () {
    Http::fake([
        'andalus.test/api/v1/accounts' => Http::response(['message' => 'nope'], 401),
        'andalus.test/api/v1/contacts' => fn () => throw new Illuminate\Http\Client\ConnectionException('cURL error 28: timeout'),
    ]);

    $driver = BankSession::factory()->authenticated()->create()->driver();

    rescue(fn () => $driver->accounts(), report: false);
    rescue(fn () => $driver->contacts(), report: false);

    $unauthorized = BankRequest::where('url', 'like', '%/accounts')->first();
    $timeout = BankRequest::where('url', 'like', '%/contacts')->first();

    expect($unauthorized->status)->toBe(401)
        ->and($unauthorized->isSuccessful())->toBeFalse()
        ->and($unauthorized->request_headers['Authorization'])->toBe('[redacted]')
        ->and($timeout->status)->toBeNull()
        ->and($timeout->error)->toContain('cURL error 28');
});

it('masks form encoded credentials', function () {
    config()->set('banks.banks.nab.base_url', 'https://nab.test');
    Http::fake(['nab.test/*' => Http::response(['accessToken' => 'x'], 401)]);

    $session = BankSession::factory()->forBank(Bank::Nab)->create();
    rescue(fn () => $session->driver()->login('927', 'pass-123'), report: false);

    expect(BankRequest::first()->request_body)->toContain('"password":"[redacted]"')->not->toContain('pass-123');
});

it('can be disabled and prunes old entries', function () {
    config()->set('banks.request_log.enabled', false);
    Http::fake(['andalus.test/*' => Http::response(['data' => []])]);

    BankSession::factory()->authenticated()->create()->driver()->accounts();

    expect(BankRequest::count())->toBe(0);

    BankRequest::create(['bank' => 'andalus', 'method' => 'GET', 'url' => 'x', 'created_at' => now()->subDays(31)]);
    BankRequest::create(['bank' => 'andalus', 'method' => 'GET', 'url' => 'y', 'created_at' => now()->subDay()]);

    $this->artisan('model:prune', ['--model' => [BankRequest::class]])->assertSuccessful();

    expect(BankRequest::count())->toBe(1);
});
