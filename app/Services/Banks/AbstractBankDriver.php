<?php

namespace App\Services\Banks;

use App\Enums\Bank;
use App\Exceptions\BankApiException;
use App\Models\BankSession;
use App\Services\Banks\Contracts\BankDriver;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

abstract class AbstractBankDriver implements BankDriver
{
    public function __construct(
        protected readonly Bank $bank,
        protected readonly BankSession $session,
    ) {}

    public function bank(): Bank
    {
        return $this->bank;
    }

    public function session(): BankSession
    {
        return $this->session;
    }

    public function ensureAuthenticated(): bool
    {
        if ($this->session->isAuthenticated()) {
            return true;
        }

        try {
            return $this->reauthenticate();
        } catch (Throwable) {
            return false;
        }
    }

    public function logout(): void
    {
        //
    }

    /**
     * Obtain a fresh access token without user interaction. Returns false when that is impossible.
     */
    abstract protected function reauthenticate(): bool;

    /**
     * Base HTTP client for the bank, without any authorization header.
     */
    protected function http(): PendingRequest
    {
        $client = Http::baseUrl($this->bank->config('base_url'))
            ->acceptJson()
            ->timeout((int) $this->bank->config('timeout', 30))
            ->withOptions(array_filter([
                'verify' => (bool) $this->bank->config('verify_ssl', true),
                'proxy' => $this->bank->config('proxy'),
            ], fn (mixed $option) => $option !== null && $option !== ''));

        $userAgent = $this->bank->config('user_agent');

        return $userAgent ? $client->withUserAgent($userAgent) : $client;
    }

    /**
     * HTTP client carrying the session's current access token.
     */
    protected function authenticated(): PendingRequest
    {
        if ($this->session->access_token === null) {
            throw new BankApiException('Session is not authenticated.');
        }

        return $this->http()->withToken($this->session->access_token);
    }

    /**
     * Throw a BankApiException carrying the bank's complete error for failed responses.
     *
     * The bank's own message (from $messagePath), any field errors and its error
     * code are all kept, so the user sees exactly what the bank said.
     */
    protected function throwIfFailed(Response $response, ?string $messagePath = null): Response
    {
        if ($response->successful()) {
            return $response;
        }

        $payload = $response->json();
        $message = $messagePath !== null ? $response->json($messagePath) : null;

        if (! is_string($message) || $message === '') {
            $message = is_array($payload)
                ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : trim($response->body());
        }

        throw new BankApiException(
            BankApiException::describe($this->bank, $response->status(), $message ?: 'empty response', $payload),
            $response->status(),
            $payload,
        );
    }

    /** @param  array<string, mixed>  $meta */
    protected function rememberMeta(array $meta): void
    {
        $this->session->update(['meta' => array_merge($this->session->meta ?? [], $meta)]);
    }

    protected function meta(string $key, mixed $default = null): mixed
    {
        return data_get($this->session->meta, $key, $default);
    }

    protected function credential(string $key): ?string
    {
        return data_get($this->session->credentials, $key);
    }

    protected function formatAmount(float|int|string $amount, string $currency, int $decimals = 3): string
    {
        return number_format((float) $amount, $decimals, '.', ',').' '.$currency;
    }
}
