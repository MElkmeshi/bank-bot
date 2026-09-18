<?php

namespace App\Services\Banks;

use App\Enums\Bank;
use App\Models\BankSession;
use App\Services\Banks\Contracts\BankDriver;
use App\Services\Banks\Drivers\AtibDriver;
use App\Services\Banks\Drivers\JumhouriaDriver;
use App\Services\Banks\Drivers\NabDriver;
use App\Services\Banks\Drivers\NeptuneDriver;
use Closure;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Resolves the driver that speaks a bank's API for a given session.
 *
 * Mirrors Laravel's Manager classes: each bank names a driver in
 * config/banks.php and custom drivers can be registered with extend().
 */
class BankManager
{
    /** @var array<string, class-string<BankDriver>|Closure(Bank, BankSession): BankDriver> */
    protected array $drivers = [
        'neptune' => NeptuneDriver::class,
        'jumhouria' => JumhouriaDriver::class,
        'nab' => NabDriver::class,
        'atib' => AtibDriver::class,
    ];

    public function __construct(
        protected readonly Container $container,
    ) {}

    public function forSession(BankSession $session): BankDriver
    {
        return $this->driver($session->bank, $session);
    }

    public function driver(Bank $bank, BankSession $session): BankDriver
    {
        $driver = $this->drivers[$bank->driver()] ?? null;

        if ($driver === null) {
            throw new InvalidArgumentException("Bank driver [{$bank->driver()}] is not supported.");
        }

        if ($driver instanceof Closure) {
            return $driver($bank, $session);
        }

        return $this->container->make($driver, ['bank' => $bank, 'session' => $session]);
    }

    /** @param  class-string<BankDriver>|Closure(Bank, BankSession): BankDriver  $driver */
    public function extend(string $name, string|Closure $driver): static
    {
        $this->drivers[$name] = $driver;

        return $this;
    }
}
