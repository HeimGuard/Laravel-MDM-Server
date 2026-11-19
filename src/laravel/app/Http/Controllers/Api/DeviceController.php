<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Enrolment;
use App\Models\ScriptRun;
use App\Support\DeviceReportInbox;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * The device endpoints of the agent that are about the device itself: report, enrolment, key, realtime, heartbeat.
 * Every one is signed in both directions (App\Http\Middleware\DeviceSignature), see routes/api.php.
 */
class DeviceController extends Controller
{
    /**
     * The report of the agent (every few minutes): stored through the inbox, answered with the commands and hints it needs.
     */
    public function report(Request $request)
    {
        /** @var Device $device */
        $device = auth()->user();

        abort_if($device === null, 401);

        // A report that is not the agent's JSON (or has no machine) is an error the agent logs (an alert of the device), not an empty answer.
        $data = json_decode($request->getContent(), true);
        if (! is_array($data) || ! is_array($data['machine'] ?? null)) {
            return response()->json(['error' => 'invalid_report', 'message' => 'The report is not a JSON object with a machine.'], 422);
        }

        $device->drives = $data['machine']['Drives'] ?? [];
        // The address the device reaches the server from (the first proxy hop when behind one): devices
        // behind the same one are in the same network, which Wake-on-LAN relays rely on. Only a hint,
        // the device can send any header.
        $forwarded = trim(explode(',', (string) $request->header('X-Forwarded-For'))[0]);
        $device->public_ip = filter_var($forwarded, FILTER_VALIDATE_IP) ? $forwarded : $request->ip();
        $device->name = is_string($data['machine']['Hostname'] ?? null) && $data['machine']['Hostname'] !== '' ? mb_substr($data['machine']['Hostname'], 0, 255) : $device->name;
        $device->os = is_string($data['machine']['os'] ?? null) ? mb_substr($data['machine']['os'], 0, 255) : '';

        $report = json_encode($data);
        $device->last_http_at = now();
        // The commands column is not written here: queued commands are taken atomically below. Nor is the report
        // itself: it goes through the cache to a job (the rest of what depends on it is done there).
        $device->save();
        DeviceCommand::completeWakes($device->id);
        DeviceReportInbox::push($device, $report);
        // The answer below is worked out from this report (the networks of the device), not from the stored one.
        $device->setAttribute('data', $report);

        return response()->json([
            // Commands not delivered over the WebSocket; queued meanwhile ones wait for the next report.
            ...Device::commandResponse($device->id),
            // Script runs queued while the device was offline.
            'scripts_pending' => $device->signsRequests && ScriptRun::query()->where('device_id', $device->id)->where('status', 'pending')->exists(),
            // Ping-only devices in its network this agent pings with every heartbeat (agents 1.10.0+).
            'ping_targets' => $device->signsRequests ? Device::pingTargetsFor($device) : [],
            // Where the agent asks for the public address of each interface, once an hour (agents
            // 1.21.0+, network map); none with MDM_DETECT_PUBLIC_ADDRESS=false.
            'public_address_url' => config('mdm.detect_public_address') ? (config('mdm.public_address_url') ?: null) : null,
        ]);
    }

    /**
     * Enrolment: a device with a valid code gets its token (and registers its public key when it sends one).
     */
    public function register(Request $request)
    {
        $invitation = Enrolment::where('code', (string) $request->json('enrolment_code'))->where('expire_at', '>', CarbonImmutable::now())->first();

        if (null === $invitation) {
            // Too many wrong codes (guessing): the open ones are dropped, a new one is made on the page.
            if (RateLimiter::attempt('enrolment-failures', Enrolment::MAX_FAILURES, fn () => true, 900) === false) {
                Enrolment::query()->delete();
            }

            return response()->json(['error' => 'invalid_enrolment_code'], 422);
        }

        $token = Str::random(60);

        $device = new Device();
        $device->token = hash('sha256', $token);
        // Agents 1.7.0+ send their public key, the request is signed with it (checked by the middleware).
        if ($key = $request->attributes->get('mdm_public_key')) {
            $device->public_key = $key;
            $device->key_registered_at = now();
        }
        $device->save();

        $invitation->delete();
        $request->attributes->set('mdm_device_id', $device->id);

        return response()->json([
            'token' => $token,
            'device_id' => $device->id,
        ]);
    }

    /**
     * Connection details for the agent's WebSocket (Reverb / Pusher protocol) client.
     */
    public function realtime(Request $request)
    {
        /** @var Device $device */
        $device = $request->user();

        if (config('broadcasting.default') !== 'reverb') {
            return response()->json(['enabled' => false]);
        }

        $options = config('broadcasting.connections.reverb.options');
        $host = $options['host'] ?? null;
        $port = (int) ($options['port'] ?? 0);
        $scheme = $options['scheme'] ?? 'https';

        // REVERB_HOST is also where the app publishes events. When it is local (the Docker image
        // publishes to Reverb inside the container) or not set, agents connect to the address they
        // reach this server on: nginx in front of the app proxies /app to Reverb.
        if (blank($host) || in_array(strtolower($host), ['localhost', '127.0.0.1', '::1', '[::1]', '0.0.0.0'], true)) {
            // Behind a TLS proxy this is http on port 80; the agent switches to 443 because its
            // -ServerUrl is https.
            $host = $request->getHost();
            $scheme = $request->getScheme();
            $port = (int) $request->getPort();
        }

        return response()->json([
            'enabled' => true,
            'key' => config('broadcasting.connections.reverb.key'),
            'host' => $host,
            'port' => $port,
            'scheme' => $scheme,
            'path' => config('reverb.servers.reverb.path', ''),
            'channel' => 'private-device.'.$device->id,
            'auth_url' => url('/api/broadcasting/auth'),
        ]);
    }

    /**
     * Heartbeat fallback for agents without a WebSocket connection.
     */
    public function heartbeat(Request $request)
    {
        Device::recordHeartbeat($request->user()->id, $request->input('metrics'), 'http', $request->input('state'));
        // Woken (Wake-on-LAN): the relay's wake is done.
        DeviceCommand::completeWakes($request->user()->id);

        return response()->noContent();
    }

    /**
     * A device updated to a signing agent registers its key once (trust on first use, with the
     * device token). The request is signed with that key; a registered key is only replaced after
     * an admin resets it.
     */
    public function key(Request $request)
    {
        /** @var Device $device */
        $device = $request->user();
        if ($device->public_key !== null) {
            return response()->json(['error' => 'key_already_registered'], 409);
        }
        $key = $request->attributes->get('mdm_public_key');
        if ($key === null) {
            return response()->json(['error' => 'signature_required'], 401);
        }

        $updated = Device::query()->whereKey($device->id)->whereNull('public_key')->toBase()
            ->update(['public_key' => json_encode($key), 'key_registered_at' => now()]);

        return $updated === 1
            ? response()->json(['device_id' => $device->id])
            : response()->json(['error' => 'key_already_registered'], 409);
    }
}
