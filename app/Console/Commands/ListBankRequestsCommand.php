<?php

namespace App\Console\Commands;

use App\Models\BankRequest;
use Illuminate\Console\Command;

class ListBankRequestsCommand extends Command
{
    protected $signature = 'bank:requests
        {--bank= : Only requests to this bank}
        {--session= : Only requests made for this bank session ID}
        {--failed : Only failed requests (errors or HTTP >= 400)}
        {--limit=30 : Number of requests to show}
        {--id= : Show one request in full, including headers and bodies}';

    protected $description = 'Browse the recorded HTTP requests made to the banks';

    public function handle(): int
    {
        if ($id = $this->option('id')) {
            return $this->show((int) $id);
        }

        $query = BankRequest::query()->latest('id');

        if ($bank = $this->option('bank')) {
            $query->where('bank', $bank);
        }

        if ($session = $this->option('session')) {
            $query->where('bank_session_id', $session);
        }

        if ($this->option('failed')) {
            $query->where(fn ($q) => $q->whereNotNull('error')->orWhere('status', '>=', 400));
        }

        $requests = $query->limit((int) $this->option('limit'))->get();

        if ($requests->isEmpty()) {
            $this->warn('No requests recorded.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Time', 'Bank', 'Session', 'Method', 'URL', 'Status', 'ms', 'Error'],
            $requests->map(fn (BankRequest $request) => [
                $request->id,
                $request->created_at->format('m-d H:i:s'),
                $request->bank->value,
                $request->bank_session_id ?? '-',
                $request->method,
                mb_strimwidth(preg_replace('#^https?://[^/]+#', '', $request->url), 0, 70, '…'),
                $request->status ?? '-',
                $request->duration_ms ?? '-',
                $request->error ? mb_strimwidth($request->error, 0, 40, '…') : '',
            ])
        );

        $this->line('Use --id=<ID> to see a request in full.');

        return self::SUCCESS;
    }

    private function show(int $id): int
    {
        $request = BankRequest::find($id);

        if (! $request) {
            $this->error("Request {$id} not found.");

            return self::FAILURE;
        }

        $this->info("[{$request->id}] {$request->bank->value} · session {$request->bank_session_id} · {$request->created_at} · {$request->duration_ms} ms");
        $this->line("{$request->method} {$request->url}");
        $this->newLine();
        $this->comment('Request headers:');
        $this->line(json_encode($request->request_headers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->comment('Request body:');
        $this->line($this->pretty($request->request_body));
        $this->newLine();
        $this->comment('Response: HTTP '.($request->status ?? '-').($request->error ? " · error: {$request->error}" : ''));
        $this->line(json_encode($request->response_headers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->comment('Response body:');
        $this->line($this->pretty($request->response_body));

        return self::SUCCESS;
    }

    private function pretty(?string $body): string
    {
        if ($body === null || $body === '') {
            return '(empty)';
        }

        $decoded = json_decode($body, true);

        return json_last_error() === JSON_ERROR_NONE
            ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : $body;
    }
}
