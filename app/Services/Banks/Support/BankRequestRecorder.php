<?php

namespace App\Services\Banks\Support;

use App\Enums\Bank;
use App\Models\BankRequest;
use App\Models\BankSession;
use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Guzzle middleware that stores every request to a bank in bank_requests.
 *
 * Secret headers are redacted and credential fields in request bodies are
 * masked; everything else is kept verbatim (encrypted at rest) so a bank's
 * behaviour can be inspected later without re-capturing traffic.
 */
class BankRequestRecorder
{
    private const REDACTED = '[redacted]';

    private const SECRET_HEADERS = ['authorization', 'x-kony-authorization', 'x-kony-app-secret', 'x-goog-api-key', 'cookie', 'set-cookie'];

    private const SECRET_FIELDS = ['password', 'pin', 'newpin', 'secret'];

    private const MAX_BODY_BYTES = 262144;

    public function __construct(
        private readonly Bank $bank,
        private readonly ?BankSession $session = null,
    ) {}

    public static function enabled(): bool
    {
        return (bool) config('banks.request_log.enabled', true);
    }

    /**
     * @return Closure(callable): Closure(RequestInterface, array): PromiseInterface
     */
    public function middleware(): Closure
    {
        return fn (callable $handler) => function (RequestInterface $request, array $options) use ($handler): PromiseInterface {
            $startedAt = microtime(true);

            try {
                $promise = $handler($request, $options);
            } catch (Throwable $reason) {
                $this->record($request, $startedAt, error: $reason->getMessage());

                throw $reason;
            }

            return $promise->then(
                function (ResponseInterface $response) use ($request, $startedAt) {
                    $this->record($request, $startedAt, response: $response);

                    return $response;
                },
                function (Throwable $reason) use ($request, $startedAt) {
                    $response = method_exists($reason, 'getResponse') ? $reason->getResponse() : null;
                    $this->record($request, $startedAt, response: $response, error: $reason->getMessage());

                    throw $reason;
                },
            );
        };
    }

    private function record(RequestInterface $request, float $startedAt, ?ResponseInterface $response = null, ?string $error = null): void
    {
        try {
            BankRequest::create([
                'bank' => $this->bank,
                'bank_session_id' => $this->session?->id,
                'method' => $request->getMethod(),
                'url' => (string) $request->getUri(),
                'request_headers' => $this->headers($request),
                'request_body' => $this->maskCredentials($this->body($request)),
                'status' => $response?->getStatusCode(),
                'response_headers' => $response ? $this->headers($response) : null,
                'response_body' => $response ? $this->body($response) : null,
                'error' => $error,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('BankRequestRecorder: failed to store request', ['error' => $e->getMessage(), 'url' => (string) $request->getUri()]);
        }
    }

    /** @return array<string, string> */
    private function headers(MessageInterface $message): array
    {
        $headers = [];

        foreach ($message->getHeaders() as $name => $values) {
            $headers[$name] = in_array(strtolower($name), self::SECRET_HEADERS, true) ? self::REDACTED : implode(', ', $values);
        }

        return $headers;
    }

    private function body(MessageInterface $message): ?string
    {
        $stream = $message->getBody();

        if ($stream->getSize() === 0) {
            return null;
        }

        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        $body = $stream->isSeekable() || ! $stream->eof() ? (string) $stream : '';

        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        if ($body === '') {
            return null;
        }

        return mb_strlen($body, '8bit') > self::MAX_BODY_BYTES
            ? mb_strcut($body, 0, self::MAX_BODY_BYTES).'…[truncated]'
            : $body;
    }

    private function maskCredentials(?string $body): ?string
    {
        if ($body === null) {
            return null;
        }

        $fields = implode('|', array_map(preg_quote(...), self::SECRET_FIELDS));

        $body = preg_replace_callback(
            '/("(?:'.$fields.')"\s*:\s*")(?:[^"\\\\]|\\\\.)*(")/i',
            fn (array $match) => $match[1].self::REDACTED.$match[2],
            $body
        ) ?? $body;

        return preg_replace(
            '/(?<=^|[&?])((?:'.$fields.')=)[^&\s]*/i',
            '$1'.self::REDACTED,
            $body
        ) ?? $body;
    }
}
