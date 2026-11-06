<?php

namespace App\Jobs;

use App\Models\Device;
use App\Models\NetworkNeighbour;
use App\Models\NetworkSite;
use App\Support\DeviceReportInbox;
use App\Support\DiskUsage;
use App\Support\LiveUpdates;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Stores the report of a device that DeviceReportInbox::push put into the cache and does what depends on it: the
 * neighbours it sees, the public addresses it is behind, the announcement to the portal (the pages reload). The
 * columns the request itself writes (name, system, address, time of the report) are not touched here.
 */
class ProcessDeviceReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    /** @param  string|null  $json  the report itself, when it did not go through the cache */
    public function __construct(public int $deviceId, public ?string $json = null) {}

    /** One report of a device at a time. */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('report:'.$this->deviceId))->releaseAfter(10)->expireAfter(180)];
    }

    public function handle(): void
    {
        $json = $this->json ?? DeviceReportInbox::pull($this->deviceId);
        if ($json === null) {
            // Replaced by a newer report, which has a job of its own, or expired.
            return;
        }
        $data = json_decode($json, true);
        $device = is_array($data) ? Device::find($this->deviceId) : null;
        if ($device === null) {
            return;
        }
        // The report is split (the lists of it are the inventory of the device, ReportInventoryService) and stored without touching
        // updated_at (the time of the report, set by the request).
        $device->timestamps = false;
        $device->data = $json;
        $device->save();
        // The usage of each disk, for the history.
        rescue(fn () => DiskUsage::record($device));
        // What the agent sees in its networks (agents 1.16.0+, unless network_discovery is off).
        rescue(fn () => NetworkNeighbour::record($device, $data['neighbours'] ?? null));
        // The public addresses it is behind, kept for the network map.
        rescue(fn () => NetworkSite::record(NetworkSite::of($device)));
        LiveUpdates::device($device->id, 'report');
    }
}
