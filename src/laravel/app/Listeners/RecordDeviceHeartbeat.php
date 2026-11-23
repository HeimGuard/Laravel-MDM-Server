<?php

namespace App\Listeners;

use App\Models\Device;
use App\Support\LiveUpdates;
use App\Support\Signing;
use Illuminate\Support\Facades\Cache;
use Laravel\Reverb\Events\MessageReceived;
use Laravel\Reverb\Protocols\Pusher\Contracts\ChannelManager;
use Throwable;

/**
 * Runs inside the Reverb server: records "client-heartbeat" events agents send on their channel.
 */
class RecordDeviceHeartbeat
{
    public function __construct(
        private ChannelManager $channels,
    ) {}

    public function handle(MessageReceived $event): void
    {
        $message = json_decode($event->message, true);

        if (($message['event'] ?? null) !== 'client-heartbeat') {
            return;
        }

        if (! preg_match('/^private-device\.(\d+)$/', $message['channel'] ?? '', $matches)) {
            return;
        }

        // Only a connection that passed channel authorization may report for the device.
        $channel = $this->channels->for($event->connection->app())->find($message['channel']);
        if (! $channel?->find($event->connection)) {
            return;
        }

        try {
            $data = self::verifiedData((int) $matches[1], $message['data'] ?? null);
            if ($data === null) {
                return;
            }
            // Metrics at the top level (older agents), live state in "state".
            Device::recordHeartbeat((int) $matches[1], $data, 'ws', is_array($data) ? ($data['state'] ?? null) : null, false);
            LiveUpdates::fromReverb($event->connection->app(), (int) $matches[1], 'heartbeat');
        } catch (Throwable $e) {
            // Reverb swallows listener exceptions (its logger is off without --debug), report them
            // to the application log so they show up next to the web errors.
            report($e);
        }
    }

    /**
     * The heartbeat data of a signing agent (1.7.0+): {p, sig}, where p is the JSON
     * {device_id, ts, nonce, metrics, state} signed with the device key. Unsigned data only from
     * devices without a key, unless signed agents are required. Null when it is rejected.
     */
    public static function verifiedData(int $deviceId, mixed $data): ?array
    {
        $key = Device::query()->whereKey($deviceId)->value('public_key');
        $key = is_string($key) ? json_decode($key, true) : $key;
        if ($key === null) {
            return config('mdm.require_signed_agents') ? null : (is_array($data) ? $data : []);
        }

        $payload = is_array($data) ? ($data['p'] ?? null) : null;
        if (! is_string($payload) || ! Signing::verify((array) $key, 'MDM1-HB', $payload, $data['sig'] ?? null)) {
            return null;
        }

        $signed = json_decode($payload, true);
        $nonce = (string) ($signed['nonce'] ?? '');
        if (($signed['device_id'] ?? null) !== $deviceId
            || abs(time() - (int) ($signed['ts'] ?? 0)) > config('mdm.signature_max_skew', 300)
            || ! preg_match('/^[0-9a-f]{32}$/', $nonce)
            || ! Cache::add("mdm-nonce:$deviceId:$nonce", true, 2 * config('mdm.signature_max_skew', 300))) {
            return null;
        }

        $metrics = is_array($signed['metrics'] ?? null) ? $signed['metrics'] : [];

        return $metrics + ['state' => $signed['state'] ?? null];
    }
}
