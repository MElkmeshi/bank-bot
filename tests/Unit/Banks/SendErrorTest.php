<?php

use App\Enums\Bank;
use App\Telegram\Concerns\ResolvesBankSession;
use SergiX44\Nutgram\Nutgram;

function errorSender(): object
{
    return new class
    {
        use ResolvesBankSession;

        public Bank $bank = Bank::Nuran;

        public function report(Nutgram $bot, string $context, Throwable $e, string $advice = ''): void
        {
            $this->sendError($bot, $context, $e, $advice);
        }
    };
}

it('sends the complete error with advice', function () {
    $bot = Mockery::mock(Nutgram::class);
    $bot->shouldReceive('sendMessage')->once()->with("❌ Registration failed:\nNuran Bank responded with HTTP 426: يرجى التحديث\n\nPlease try again with /start");

    errorSender()->report($bot, 'Registration failed', new RuntimeException('Nuran Bank responded with HTTP 426: يرجى التحديث'), 'Please try again with /start');
});

it('only cuts the error where Telegram forces it', function () {
    $sent = null;
    $bot = Mockery::mock(Nutgram::class);
    $bot->shouldReceive('sendMessage')->once()->withArgs(function (string $text) use (&$sent) {
        $sent = $text;

        return true;
    });

    errorSender()->report($bot, 'Transfer failed', new RuntimeException(str_repeat('ع', 5000)), 'Retry with /transfer');

    expect(mb_strlen($sent))->toBeLessThanOrEqual(4096)
        ->and($sent)->toStartWith("❌ Transfer failed:\nعععع")
        ->and($sent)->toEndWith("…\n\nRetry with /transfer");
});
