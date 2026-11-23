<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ScriptRun;
use App\Support\LiveUpdates;
use Illuminate\Http\Request;

/**
 * Remediation script runs of a device: the signed ones waiting for it, and the result of a run.
 */
class DeviceScriptController extends Controller
{
    /**
     * Remediation scripts waiting for this device, each with its manifest signed with the server
     * key (for this device and run, valid for 24 hours). Taking them marks them sent.
     */
    public function pending(Request $request)
    {
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
            LiveUpdates::device($device->id, 'script');
        }

        return response()->json(['runs' => $payloads]);
    }

    /**
     * The result of a run: exit codes and the output (shortened), for this device's runs only.
     */
    public function result(Request $request, int $run)
    {
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
        LiveUpdates::device($device->id, 'script');

        return response()->json(['status' => $scriptRun->status]);
    }
}
