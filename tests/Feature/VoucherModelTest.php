<?php

use App\Data\Bank\VoucherData;
use App\Models\BankSession;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('records purchased vouchers with the code encrypted', function () {
    $session = BankSession::factory()->create();

    $voucher = Voucher::record($session, '1001', new VoucherData('Almadar', '3', 'LYD', '0899688514053', '00219938625', 'FT1', '2026-09-20'));

    expect($voucher->fresh()->code)->toBe('0899688514053')
        ->and($voucher->getRawOriginal('code'))->not->toContain('0899688514053')
        ->and($session->vouchers()->count())->toBe(1)
        ->and($voucher->purchased_at->toDateString())->toBe('2026-09-20');
});
