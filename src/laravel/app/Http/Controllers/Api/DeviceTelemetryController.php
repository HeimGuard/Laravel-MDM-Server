<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\PortScanResult;
use App\Support\DeviceUsage;
use App\Support\LiveUpdates;
use Illuminate\Http\Request;

/**
 * What the agent sends besides its report: metric samples collected offline, its errors, ping and port scan results.
 */
class DeviceTelemetryController extends Controller
{
    /**
     * Samples the agent collected while it could not reach the server (agents 1.11.0+): they fill
     * the gap in the CPU and memory history.
     */
    public function backfill(Request $request)
    {
        /** @var Device $device */
        $device = $request->user();
        abort_unless($device->signsRequests, 403);
        $taken = DeviceUsage::backfill($device->id, $request->json('samples'));
        if ($taken > 0) {
            LiveUpdates::device($device->id, 'metrics');
        }

        return response()->json(['taken' => $taken]);
    }

    /**
     * Errors the agent wrote to its log (agents 1.13.2+), sent once the server answers: an alert
     * of the device.
     */
    public function errors(Request $request)
    {
        /** @var Device $device */
        $device = $request->user();
        abort_unless($device->signsRequests, 403);
        $taken = $device->recordAgentErrors($request->json('errors'));
        if ($taken > 0) {
            LiveUpdates::device($device->id, 'errors');
        }

        return response()->json(['taken' => $taken]);
    }

    /**
     * Results of the pings of the ping-only devices (agents 1.10.0+): only the ones it was given.
     */
    public function pings(Request $request)
    {
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
    }

    /**
     * The result of a port scan (agents 1.18.0+): the open ports of one address in the agent's
     * network with a passive identification of the services. Only an address in the agent's own
     * networks, when the device and the portal allow port scans; everything the host returned is
     * stored as untrusted plain text.
     */
    public function portScan(Request $request)
    {
        /** @var Device $device */
        $device = $request->user();
        abort_unless($device->signsRequests, 403);
        $taken = PortScanResult::record($device, $request->json()->all());
        if ($taken) {
            LiveUpdates::device($device->id, 'port-scan');
        }

        return response()->json(['taken' => $taken]);
    }
}
