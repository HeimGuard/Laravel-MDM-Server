<?php

namespace App\Jobs;

use App\Services\DockerIconService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use SteelAnts\LaravelBoilerplate\Attributes\AllowManualRun;

/**
 * Reads the index of the icon databases and stores the icons of the containers the devices run
 * (weekly, when a page misses an icon, or by hand: `php artisan mdm:docker-icons`).
 */
#[AllowManualRun]
class RefreshDockerIcons implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public bool $force = false) {}

    public function handle(): void
    {
        $result = DockerIconService::refresh($this->force);
        if ($result['failed'] !== []) {
            Log::warning('Docker icons: '.implode('; ', array_slice($result['failed'], 0, 5)));
        }
    }
}
