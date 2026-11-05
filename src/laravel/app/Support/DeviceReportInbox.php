<?php

namespace App\Support;

use App\Jobs\ProcessDeviceReport;
use App\Models\Device;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * The report of an agent (everything it knows about the machine) is large, so taking it is only putting it into the
 * cache (compressed and encrypted, as SecurityInbox does) and queueing a job: the request is answered right away.
 * ProcessDeviceReport (run by the queue worker) stores it and does the rest. Only the newest report of a device is
 * kept: when the worker is behind, an older one that was not processed yet is replaced, not queued up.
 * Without a queue (QUEUE_CONNECTION=sync) the job runs once the response has been sent.
 */
class DeviceReportInbox
{
    /** A report not processed within this time is dropped (the next one comes in minutes). */
    public const TTL = 3600;

    public static function key(int $deviceId): string
    {
        return 'mdm.device_report.'.$deviceId;
    }

    /**
     * Takes the report (its JSON) of a device. When the cache does not take it, it is processed right away: a report
     * must not get lost.
     */
    public static function push(Device $device, string $json): void
    {
        try {
            Cache::put(self::key($device->id), Crypt::encryptString(base64_encode(gzencode($json, 3))), self::TTL);
        } catch (Throwable $e) {
            report($e);
            ProcessDeviceReport::dispatchSync($device->id, $json);

            return;
        }
        if (config('queue.default') === 'sync') {
            ProcessDeviceReport::dispatchAfterResponse($device->id);
        } else {
            ProcessDeviceReport::dispatch($device->id);
        }
    }

    /** The newest report of the device, once: null when there is none (a job of an older report that was replaced). */
    public static function pull(int $deviceId): ?string
    {
        $stored = Cache::pull(self::key($deviceId));
        if (! is_string($stored)) {
            return null;
        }
        try {
            $json = @zlib_decode(base64_decode(Crypt::decryptString($stored), true) ?: '', SecurityInbox::MAX_BYTES);
        } catch (Throwable) {
            return null;
        }

        return is_string($json) ? $json : null;
    }
}
