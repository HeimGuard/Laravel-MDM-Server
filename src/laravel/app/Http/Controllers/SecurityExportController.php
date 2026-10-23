<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\SecurityFinding;
use App\Support\SecurityExport;
use Illuminate\Http\Request;

/**
 * The findings of the scanner as a Markdown report: the Security page (its filters), or one device (its tab). A plain
 * download that is written as it is read, not an answer of Livewire (which holds the whole report and sends it
 * inside a JSON answer for the browser to put together).
 */
class SecurityExportController extends Controller
{
    public function findings(Request $request)
    {
        $data = $request->validate([
            'device' => ['nullable', 'integer', 'exists:devices,id'],
            'status' => ['nullable', 'in:open,acknowledged,resolved,unresolved'],
            'severity' => ['nullable', 'string', 'max:16'],
            'search' => ['nullable', 'string', 'max:200'],
        ]);
        $device = isset($data['device']) ? Device::findOrFail($data['device']) : null;
        $query = SecurityFinding::query()->filtered($data['status'] ?? 'open', $data['severity'] ?? '', $data['search'] ?? '')
            ->when($device, fn ($query) => $query->where('device_id', $device->id))->bySeverity();

        return response()->streamDownload(
            fn () => SecurityExport::streamMarkdown($query, $device ? 'Security findings of '.$device->displayName : 'Security findings'),
            'security-findings-'.($device ? str($device->displayName)->slug().'-' : '').now()->format('Y-m-d').'.md',
            ['Content-Type' => 'text/markdown; charset=UTF-8'],
        );
    }
}
