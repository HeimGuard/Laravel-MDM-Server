<?php

namespace App\Jobs;

use App\Support\SecurityFeed;
use App\Support\SecurityInbox;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Takes the rules of the security rules feed (once a day, or on demand from the Security page). */
class SyncSecurityFeed implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public bool $force = false)
    {
        $this->onQueue(SecurityInbox::QUEUE);
    }

    public function handle(): void
    {
        SecurityFeed::sync($this->force);
    }
}
