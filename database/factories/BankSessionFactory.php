<?php

namespace Database\Factories;

use App\Enums\Bank;
use App\Models\BankSession;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BankSession>
 */
class BankSessionFactory extends Factory
{
    protected $model = BankSession::class;

    public function definition(): array
    {
        return [
            'telegram_chat_id' => fake()->numberBetween(1000, 999999),
            'bank' => Bank::Andalus,
            'customer_id' => (string) fake()->numberBetween(100000, 999999),
            'device_id' => (string) Str::uuid(),
        ];
    }

    public function forBank(Bank $bank): static
    {
        return $this->state(['bank' => $bank]);
    }

    public function authenticated(): static
    {
        return $this->state([
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'access_token_expires_at' => now()->addHour(),
            'refresh_token_expires_at' => now()->addDay(),
        ]);
    }

    public function expired(): static
    {
        return $this->state([
            'access_token' => 'expired-token',
            'refresh_token' => 'refresh-token',
            'access_token_expires_at' => now()->subMinute(),
            'refresh_token_expires_at' => now()->addDay(),
        ]);
    }
}
