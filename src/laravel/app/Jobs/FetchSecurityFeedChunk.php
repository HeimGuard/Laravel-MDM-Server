<?php

namespace App\Jobs;

use App\Support\SecurityFeed;
use App\Support\SecurityInbox;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/** One step of a security feed sync (see SyncSecurityFeed): the steps of a run follow each other. */
class FetchSecurityFeedChunk implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public string $run, public int $index, public array $files)
    {
        $this->onQueue(SecurityInbox::QUEUE);
    }

    public function handle(): void
    {
        SecurityFeed::fetchChunk($this->run, $this->index, $this->files);
    }

    /** A killed or crashed job (a timeout) stops the run too. */
    public function failed(Throwable $e): void
    {
        SecurityFeed::abort($this->run, $e);
    }
}
