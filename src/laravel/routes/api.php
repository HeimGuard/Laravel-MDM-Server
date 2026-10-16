<?php

use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Enrolment;
use App\Models\ScriptRun;
use App\Support\SecurityInbox;
use App\Support\SecurityParsers;
use App\Support\SecurityScanner;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

// Every device endpoint is signed in both directions (App\Http\Middleware\DeviceSignature).
Route::middleware(['device.signature', 'auth:api'])->post('/device', function (Request $request) {
    /** @var Device $device */
    $device = auth()->user();

    if ($device === null) {
        return;
    }

    $data = json_decode($request->getContent(), true);

    if ($data === null) {
        return;
    }

    $device->drives = $data['machine']['Drives'];
    // The address the device reaches the server from (the first proxy hop when behind one): devices
    // behind the same one are in the same network, which Wake-on-LAN relays rely on. Only a hint,
    // the device can send any header.
    $forwarded = trim(explode(',', (string) $request->header('X-Forwarded-For'))[0]);
    $device->public_ip = filter_var($forwarded, FILTER_VALIDATE_IP) ? $forwarded : $request->ip();
    $device->name = $data['machine']['Hostname'];
    $device->os = $data['machine']['os'] ?? '';

    $device->data = json_encode($data);
    $device->last_http_at = now();
    // The commands column is not written here: queued commands are taken atomically below.
    $device->save();
    App\Models\DeviceCommand::completeWakes($device->id);
    // What the agent sees in its networks (agents 1.16.0+, unless network_discovery is off).
    rescue(fn () => App\Models\NetworkNeighbour::record($device, $data['neighbours'] ?? null));
    // The public addresses it is behind, kept for the network map.
    rescue(fn () => App\Models\NetworkSite::record(App\Models\NetworkSite::of($device)));
    App\Support\LiveUpdates::device($device->id, 'report');

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
});

Route::middleware(['device.signature', 'throttle:10,1'])->post('/device/register', function (Request $request) {
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
});

Route::middleware(['device.signature', 'auth:api'])->group(function () {
    // Connection details for the agent's WebSocket (Reverb / Pusher protocol) client.
    Route::get('/device/realtime', function (Request $request) {
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
    });

    // Heartbeat fallback for agents without a WebSocket connection.
    Route::post('/device/heartbeat', function (Request $request) {
        Device::recordHeartbeat($request->user()->id, $request->input('metrics'), 'http', $request->input('state'));
        // Woken (Wake-on-LAN): the relay's wake is done.
        App\Models\DeviceCommand::completeWakes($request->user()->id);

        return response()->noContent();
    });

    // The security inventory as deltas against the state the server has (hash per source), and the
    // raw logs since the previous collection (agents 1.17.0+, hourly). Only checked and put into
    // the cache here: a queued job parses, stores and scans it (App\Support\SecurityInbox).
    // 409 {resync: [sources]} when a delta does not follow the stored state: nothing is taken, the
    // agent sends those sources whole. 202 {ack: {source: state}} when it is taken; a collection
    // sent twice (collection_id) is acknowledged again and processed once.
    Route::post('/device/security', function (Request $request) {
        /** @var Device $device */
        $device = $request->user();
        abort_unless($device->signsRequests, 403);
        $raw = $request->getContent();
        abort_if(strlen($raw) > SecurityInbox::MAX_BYTES, 413);
        $gzip = strtolower((string) $request->header('Content-Encoding')) === 'gzip';
        // What is wrong goes back as the error, so the agent's log (an alert of the device) says it.
        $json = SecurityInbox::decode($raw, $gzip);
        if ($json === null) {
            return response()->json(['error' => 'not a JSON object, or more than '.(SecurityInbox::MAX_BYTES >> 20).' MB unpacked'], 422);
        }
        $payload = json_decode($json, true);
        $sources = SecurityScanner::normalizeSources($payload['sources'] ?? [], $problem);
        $id = $payload['collection_id'] ?? null;
        if ($sources === null || ! is_string($id) || ! preg_match('/^[0-9a-f-]{16,64}$/i', $id)) {
            return response()->json(['error' => $problem ?? 'collection_id: missing or not an id'], 422);
        }

        // The log positions the agent may move on to once this collection is taken.
        $cursors = array_filter($payload['cursors'] ?? [], fn ($cursor, $source) => isset(SecurityParsers::LOG_SOURCES[$source]) && (is_string($cursor) || is_int($cursor)) && strlen((string) $cursor) <= 512, ARRAY_FILTER_USE_BOTH);
        $ack = ['ack' => collect($sources)->pluck('state', 'source')->all(), 'cursors' => $cursors];
        // Sent again after a timeout: acknowledged, not processed twice.
        if (! Cache::add("mdm.security_collection.{$device->id}.{$id}", 1, SecurityInbox::TTL)) {
            return response()->json(['queued' => true, 'duplicate' => true] + $ack, 202);
        }
        if ($wrong = SecurityScanner::mismatches($device, $sources)) {
            Cache::forget("mdm.security_collection.{$device->id}.{$id}");

            return response()->json(['resync' => $wrong], 409);
        }
        SecurityInbox::push($device, $raw, $gzip);

        return response()->json(['queued' => true] + $ack, 202);
    });

    // What the agent has to send of its logs (agents 1.17.0+, when security_logs is on): the values of
    // the identifier / event id the enabled parsers read, per log, so it filters at the source.
    Route::get('/device/security/policy', function (Request $request) {
        abort_unless($request->user()->signsRequests, 403);

        return response()->json(SecurityScanner::policy());
    });

    // Samples the agent collected while it could not reach the server (agents 1.11.0+): they fill
    // the gap in the CPU and memory history.
    Route::post('/device/metrics/backfill', function (Request $request) {
        /** @var Device $device */
        $device = $request->user();
        abort_unless($device->signsRequests, 403);
        $taken = App\Models\DeviceMetric::backfill($device->id, $request->json('samples'));
        if ($taken > 0) {
            App\Support\LiveUpdates::device($device->id, 'metrics');
        }

        return response()->json(['taken' => $taken]);
    });

    // Errors the agent wrote to its log (agents 1.13.2+), sent once the server answers: an alert
    // of the device.
    Route::post('/device/errors', function (Request $request) {
        /** @var Device $device */
        $device = $request->user();
        abort_unless($device->signsRequests, 403);
        $taken = $device->recordAgentErrors($request->json('errors'));
        if ($taken > 0) {
            App\Support\LiveUpdates::device($device->id, 'errors');
        }

        return response()->json(['taken' => $taken]);
    });

    // Results of the pings of the ping-only devices (agents 1.10.0+): only the ones it was given.
    Route::post('/device/pings', function (Request $request) {
        /** @var Device $device */
        $device = $request->user();
        abort_unless($device->signsRequests, 403);

        return response()->json([
            'taken' => Device::recordPings($device, $request->json('results')),
            // Pings made while the agent could not reach the server (agents 1.11.0+).
            'backfilled' => Device::backfillPings($device, $request->json('backlog')),
            // The current list, so the agent follows changes between its reports.
            'ping_targets' => Device::pingTargetsFor($device),
        ]);
    });

    // The result of a port scan (agents 1.18.0+): the open ports of one address in the agent's
    // network with a passive identification of the services. Only an address in the agent's own
    // networks, when the device and the portal allow port scans; everything the host returned is
    // stored as untrusted plain text.
    Route::post('/device/port-scan', function (Request $request) {
        /** @var Device $device */
        $device = $request->user();
        abort_unless($device->signsRequests, 403);
        $taken = App\Models\PortScanResult::record($device, $request->json()->all());
        if ($taken) {
            App\Support\LiveUpdates::device($device->id, 'port-scan');
        }

        return response()->json(['taken' => $taken]);
    });

    // A device updated to a signing agent registers its key once (trust on first use, with the
    // device token). The request is signed with that key; a registered key is only replaced after
    // an admin resets it.
    Route::post('/device/key', function (Request $request) {
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
    });

    // Signing agents take their commands here when the WebSocket announces one: the response is
    // signed for this request, so a command cannot be replayed or injected.
    Route::post('/device/commands/take', function (Request $request) {
        return response()->json(Device::commandResponse($request->user()->id));
    });

    // Agents 1.8.0+ report what a command they took is doing: running (with an optional progress
    // in percent and a message), then succeeded or failed. Only for this device's commands.
    Route::post('/device/commands/{command}', function (Request $request, int $command) {
        /** @var Device $device */
        $device = $request->user();
        $deviceCommand = DeviceCommand::query()->whereKey($command)->where('device_id', $device->id)->first();
        abort_if($deviceCommand === null, 404);

        $status = (string) $request->json('status');
        if (! in_array($status, DeviceCommand::RESULTS, true)) {
            return response()->json(['error' => 'invalid_status'], 422);
        }
        $progress = $request->json('progress');
        $message = $request->json('message');
        $values = [
            'status' => $status,
            // 100 % only once it is done: a running command at 100 % would look stuck.
            'progress' => is_numeric($progress) ? max(0, min($status === 'running' ? 99 : 100, (int) $progress)) : ($status === 'succeeded' ? 100 : null),
            'message' => is_string($message) && $message !== '' ? mb_strcut($message, 0, 1000) : null,
            'updated_at' => now(),
        ];
        if ($status === 'running') {
            $values['started_at'] = $deviceCommand->started_at ?? now();
        } else {
            $values['finished_at'] = now();
        }

        // A finished (or given up) command is not changed anymore.
        $updated = DeviceCommand::query()->whereKey($deviceCommand->id)->whereIn('status', ['sent', 'running'])->toBase()->update($values);

        if ($updated === 1) {
            DeviceCommand::announce($device->id, $deviceCommand->target);
        }

        return $updated === 1
            ? response()->json(['status' => $status])
            : response()->json(['error' => 'not_running', 'status' => $deviceCommand->status], 409);
    })->whereNumber('command');

    // Remediation scripts waiting for this device, each with its manifest signed with the server
    // key (for this device and run, valid for 24 hours). Taking them marks them sent.
    Route::get('/device/scripts', function (Request $request) {
        /** @var Device $device */
        $device = $request->user();
        abort_unless($device->signsRequests, 403);

        $runs = ScriptRun::query()->with('script')->where('device_id', $device->id)->where('status', 'pending')->orderBy('id')->limit(20)->get();
        $payloads = [];
        foreach ($runs as $run) {
            if ($run->expires_at->isPast()) {
                $run->update(['status' => 'expired']);
                continue;
            }
            // The script changed after the run was issued: that version cannot be sent anymore.
            if ($run->currentFingerprint() !== $run->fingerprint) {
                $run->update(['status' => 'superseded']);
                continue;
            }
            // Only the request that changes it from pending sends it (no double delivery).
            if (ScriptRun::query()->whereKey($run->id)->where('status', 'pending')->update(['status' => 'sent', 'sent_at' => now()]) === 1) {
                $payloads[] = $run->toSignedPayload();
            }
        }
        if ($runs->isNotEmpty()) {
            App\Support\LiveUpdates::device($device->id, 'script');
        }

        return response()->json(['runs' => $payloads]);
    });

    // The result of a run: exit codes and the output (shortened), for this device's runs only.
    Route::post('/device/scripts/runs/{run}', function (Request $request, int $run) {
        /** @var Device $device */
        $device = $request->user();
        $scriptRun = ScriptRun::query()->whereKey($run)->where('device_id', $device->id)->first();
        abort_if($scriptRun === null, 404);
        if ($scriptRun->status !== 'sent') {
            return response()->json(['error' => 'not_running'], 409);
        }

        $status = (string) $request->json('status');
        $exit = fn (string $key) => is_numeric($request->json($key)) ? max(-32768, min(32767, (int) $request->json($key))) : null;
        $error = $request->json('error');
        // The agent reports the fingerprint it verified and ran.
        if ($request->json('fingerprint') !== $scriptRun->fingerprint && $status !== 'rejected') {
            $status = 'error';
            $error = 'The device ran a different fingerprint';
        }

        $scriptRun->status = in_array($status, ScriptRun::RESULTS, true) ? $status : 'error';
        // Only detected (manual remediation): exit 1 is "needs remediation", not a failure.
        if ($scriptRun->detectsOnly && $scriptRun->status === 'failed' && $exit('detection_exit') === 1 && $exit('remediation_exit') === null) {
            $scriptRun->status = 'noncompliant';
        }
        $scriptRun->detection_exit = $exit('detection_exit');
        $scriptRun->remediation_exit = $exit('remediation_exit');
        $scriptRun->post_detection_exit = $exit('post_detection_exit');
        $scriptRun->output = mb_strcut((string) $request->json('output'), 0, ScriptRun::MAX_OUTPUT) ?: null;
        $scriptRun->error = is_string($error) && $error !== '' ? mb_strcut($error, 0, 1000) : null;
        $scriptRun->finished_at = now();
        $scriptRun->save();
        App\Support\LiveUpdates::device($device->id, 'script');

        return response()->json(['status' => $scriptRun->status]);
    });

    // Agents before 1.7.0 acknowledge a command before executing it so it is not delivered twice.
    Route::post('/device/commands/ack', function (Request $request) {
        /** @var Device $device */
        $device = $request->user();
        $command = $request->input('command');

        // They execute the command from the WebSocket event, the acknowledgement is its delivery.
        $queued = $device->commands()->where('status', 'queued')->where('command', (string) $command)->orderBy('id')->first();
        $queued?->update(['status' => 'delivered']);
        $queued?->forceFill(['sent_at' => now(), 'finished_at' => now()])->save();

        return response()->json(['commands' => $device->queuedCommands]);
    });
});
