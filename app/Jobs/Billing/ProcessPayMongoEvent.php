<?php

namespace App\Jobs\Billing;

use App\Services\Billing\ProcessPayMongoWebhook;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessPayMongoEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 30;

    public function __construct(public int $eventId) {}

    public function backoff(): array
    {
        return [10, 30, 60, 120];
    }

    public function handle(ProcessPayMongoWebhook $processor): void
    {
        $processor->handle($this->eventId);
    }
}
