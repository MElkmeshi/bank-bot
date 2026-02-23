<?php

namespace App\Console\Commands;

use App\Jobs\CheckTransactionsJob;
use Illuminate\Console\Command;

class CheckTransactionsCommand extends Command
{
    protected $signature = 'transactions:check';

    protected $description = 'Dispatch the job to check for new transactions and notify users';

    public function handle(): void
    {
        CheckTransactionsJob::dispatchSync();

        $this->info('CheckTransactionsJob dispatched.');
    }
}
