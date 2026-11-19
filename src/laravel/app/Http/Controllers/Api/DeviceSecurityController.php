<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\SecurityInbox;
use App\Support\SecurityParsers;
use App\Support\SecurityScanner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * The security inventory and logs the agent sends (agents 1.17.0+) and the policy of what to send.
 */
class DeviceSecurityController extends Controller
{
    /**
     * The security inventory as deltas against the state the server has (hash per source), and the
     * raw logs since the previous collection (agents 1.17.0+, hourly). Only checked and put into
     * the cache here: a queued job parses, stores and scans it (App\Support\SecurityInbox).
     * 409 {resync: [sources]} when a delta does not follow the stored state: nothing is taken, the
     * agent sends those sources whole. 202 {ack: {source: state}} when it is taken; a collection
     * sent twice (collection_id) is acknowledged again and processed once.
     */
    public function collection(Request $request)
    {
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
    }

    /**
     * What the agent has to send of its logs (agents 1.17.0+, when security_logs is on): the values of
     * the identifier / event id the enabled parsers read, per log, so it filters at the source.
     */
    public function policy(Request $request)
    {
        abort_unless($request->user()->signsRequests, 403);

        return response()->json(SecurityScanner::policy());
    }
}
