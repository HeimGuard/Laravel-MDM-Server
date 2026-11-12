<?php

namespace App\Services;

use App\Models\SecurityFinding;
use App\Models\SecurityInventory;
use RuntimeException;

/**
 * "Explain with AI" of a finding: what it is, whether it concerns this device at all, how urgent it is and what to
 * do, in the language of the user. Only the description of the finding leaves the server (describe()): the rule,
 * the facts of the system it runs on and the fields of the affected item that name no person, address or secret.
 */
class FindingExplanationService
{
    private const SYSTEM = <<<'TEXT'
        You explain a finding of the security scanner of a device management server to an administrator who is
        not a security specialist. Explain in a few short paragraphs (at most about 250 words):
        - whether the finding concerns this device at all. Compare what the vulnerability needs with the facts of the
          device: the CPU architecture (a CVE of Arm cores or an arm64 build does not concern an x86_64 / AMD64 device,
          and the other way round), the operating system, its version and the kernel (a Windows CVE on Linux, a kernel
          release that is older than the bug or already has the fix), the edition or build of the package, whether the
          affected feature is used (not running, not listening). When it does not apply, or only under a condition you
          cannot see, start with that ("Probably a false positive: ...") and say what to check.
        - what the finding means (the vulnerability or the weak setting, in plain words),
        - what an attacker could do with it and how likely that is on this device (local or remote, needs a user,
          exploited in the wild),
        - how urgent it is,
        - what to do about it (the hint of the scanner, an update, a mitigation), and when nothing can be done yet.
        Linux packages (Debian, Ubuntu) get security fixes backported without a new upstream version: say so when
        the finding may already be fixed by the distribution. You cannot open the references; use what you know.
        Do not invent facts you are not sure about (CVSS scores, dates, versions): say that the reference has them.
        Plain text only, no Markdown (no #, **, tables, code blocks); short lists may start with "- ".
        TEXT;

    /** The language of the answer by the locale of the user (config mdm.locales). */
    private const LANGUAGES = ['cs' => 'Czech', 'de' => 'German', 'en' => 'English', 'es' => 'Spanish', 'fr' => 'French', 'it' => 'Italian'];

    /**
     * The explanation of the finding as plain text. Throws a RuntimeException with a message for the user when it cannot.
     */
    public static function explain(SecurityFinding $finding, ?string $locale = null): string
    {
        $language = self::LANGUAGES[$locale ?? app()->getLocale()] ?? 'English';
        $text = trim(AiService::ask(self::SYSTEM, "Explain this security finding. Answer in $language.\n".self::describe($finding), name: 'explanation', maxTokens: 4000, effort: 'low'));
        if ($text === '') {
            throw new RuntimeException(__('The AI did not answer, try again.'));
        }

        return mb_substr($text, 0, 5000);
    }

    /**
     * What the AI learns about a finding: the rule, the message, what the scanner found out about it, the hint, the
     * references of the detection, the platform and the operating system of the device.
     */
    public static function describe(SecurityFinding $finding): string
    {
        $rule = $finding->rule;
        $platform = $finding->device?->platform;
        $details = is_array($finding->details) ? $finding->details : [];

        return "Rule: {$finding->title}".($rule ? " ({$rule->key})" : '')."\nSeverity: {$finding->severity}\nFinding: {$finding->message}"
            // What the scanner found out about it: where the software comes from, what can be done about the CVEs (a fix to install, none yet).
            .(filled($details['Status'] ?? null) ? "\nStatus of the CVEs: {$details['Status']}" : '')
            .(filled($details['Cves'] ?? null) ? "\nCVEs: ".mb_strimwidth((string) $details['Cves'], 0, 600, '…') : '')
            .(filled($details['Channel'] ?? null) ? "\nSnap channel: {$details['Channel']}" : '')
            .(filled($details['Origin'] ?? null) ? "\nPackage origin: {$details['Origin']}" : '')
            .($finding->remediation ? "\nHint: {$finding->remediation}" : '')
            // The advisories and the vendor documentation of the detection: they often name a recommended mitigation.
            .($finding->urls !== [] ? "\nReferences of the detection (advisories, vendor documentation):\n".collect($finding->urls)->pluck('url')->map(fn ($url) => "- $url")->implode("\n") : '')
            .(in_array($platform, ['windows', 'linux'], true) ? "\nPlatform: {$platform}" : '')
            // The operating system and its version (Ubuntu 26.04 LTS, Microsoft Windows 11 Pro (10.0.26200)).
            .(filled($finding->device?->os) ? "\nOperating system: ".mb_strimwidth(preg_replace('/\s+/', ' ', (string) $finding->device->os), 0, 200, '…') : '')
            .self::deviceFacts($finding);
    }

    /**
     * The fields of an item of the security inventory that may leave the server, per source: what the thing is and
     * its state, never a user, a host name, an address, a path or a command line (they can hold names and secrets).
     */
    private const SAFE_FIELDS = [
        'software' => ['Name', 'Version', 'Publisher', 'Source', 'SourcePackage', 'Candidate', 'Origin', 'Channel', 'UpdateAvailable', 'NoUpdateSource', 'Running', 'Listening'],
        'processes' => ['Name', 'Count'],
        'listening' => ['Protocol', 'Port', 'Process'],
        'startup' => ['Name', 'Location'],
        'admins' => ['Source', 'Enabled'],
        'os' => ['Name', 'Version', 'Id', 'Kernel', 'Codename', 'Arch'],
        // Not AptUpdateErrors (the address of a repository can carry a password) nor UnencryptedMounts (paths).
        'posture' => [
            'FirewallEnabled', 'FirewallDetail', 'AntivirusName', 'AntivirusEnabled', 'AntivirusUpToDate', 'RealTimeProtection',
            'DiskEncrypted', 'SecureBoot', 'RdpEnabled', 'RdpNla', 'Smb1Enabled', 'UacEnabled', 'GuestEnabled', 'AutoLogon',
            'SshRootLogin', 'SshPasswordAuthentication', 'AutomaticUpdates', 'DaysSinceUpdate', 'AptUpdateOk',
        ],
        'events' => ['Type', 'Count'],
        'portscan' => ['Port', 'Service', 'Server', 'Tls', 'Http', 'Hsts', 'Csp', 'XFrameOptions', 'CertSelfSigned', 'CertExpired', 'TlsFailed'],
    ];

    /**
     * The facts of the device that tell whether the finding concerns it: the system (version, kernel, CPU architecture;
     * agents 1.30.0+ send the architecture), a virtual machine or a container, the kind of device, its security settings
     * and the affected item. Empty when there is nothing to tell.
     */
    private static function deviceFacts(SecurityFinding $finding): string
    {
        $lines = [];
        $device = $finding->device;
        $item = is_array($finding->details) ? $finding->details : [];
        $source = $finding->rule?->source;
        if (isset(self::SAFE_FIELDS[$source]) && $source !== 'posture' && ($fields = self::fields($item, self::SAFE_FIELDS[$source])) !== '') {
            $lines[] = "Affected item ($source): $fields";
        }
        if ($device) {
            $inventory = SecurityInventory::query()->where('device_id', $device->id)->first();
            if (($system = self::fields($inventory?->items('os')[0] ?? [], self::SAFE_FIELDS['os'])) !== '') {
                $lines[] = "System: $system";
            }
            $kind = $device->type.($device->virtualization ? ", {$device->virtualization['type']} ({$device->virtualization['label']})" : ', physical or unknown');
            $lines[] = "Kind of device: $kind";
            if (($posture = self::fields($inventory?->items('posture')[0] ?? [], self::SAFE_FIELDS['posture'])) !== '') {
                $lines[] = "Security settings: $posture";
            }
        }

        return $lines === [] ? '' : "\n\nFacts of the device (nothing else about it is sent):\n".implode("\n", array_map(fn ($line) => "- $line", $lines));
    }

    /** The fields of the item that are set, as "Name=value; ..." (each value short and on one line). */
    private static function fields(array $item, array $fields): string
    {
        return collect($fields)->filter(fn ($field) => isset($item[$field]) && $item[$field] !== '' && ! is_array($item[$field]))
            ->map(fn ($field) => $field.'='.(is_bool($item[$field]) ? ($item[$field] ? 'true' : 'false') : mb_strimwidth(preg_replace('/\s+/', ' ', (string) $item[$field]), 0, 120, '…')))
            ->implode('; ');
    }
}
