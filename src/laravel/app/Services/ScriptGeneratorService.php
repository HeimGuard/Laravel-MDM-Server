<?php

namespace App\Services;

use App\Models\Script;
use App\Support\PowerShellCheck;
use RuntimeException;

/**
 * Writes a remediation script from a description with the AI of the server (AiService). Only the
 * description and the script being edited leave the server; nothing about the devices.
 */
class ScriptGeneratorService
{
    private const SYSTEM = <<<'TEXT'
        You write remediation scripts for a device management server, like Intune proactive remediations.
        A script is a pair of PowerShell 7 scripts that run on Windows and Linux devices as SYSTEM / root,
        without network access and without a user to answer prompts:
        - detection: checks the state. It has to end with `exit 0` when the device is compliant and `exit 1`
          when it needs the remediation; both exits have to be in the script. Write what was found to the output.
        - remediation: fixes it (it runs when the detection exits with 1, then the detection runs again). Make it
          idempotent, non-interactive, and exit with a non-zero code when it fails. Leave it empty when the request
          only asks for a check.
        Platform: "windows" or "linux" when the scripts only work there, "all" when they handle both (use $IsWindows /
        $IsLinux). Timeout in seconds, 5 to 3600, enough for the slowest step. manual_remediation: true when the fix
        is disruptive (a restart, removing software or data, stopping services) and should be started per device
        by hand. Name (short) and description (one or two sentences) in the language of the request; comments in
        the scripts in English. When a current script is given, change it as the request asks.
        Linux packages (Debian, Ubuntu): the distribution backports security fixes without changing the upstream
        version, so 1.0.3-2 or 8.5.4-0ubuntu1.5 can already contain the fix of a CVE that the vendor fixed in a
        later upstream version. Never decide from comparing the installed version with the upstream "fixed in"
        version, and never write a detection that only compares versions. Decide with what the distribution says:
        the CVE ids in /usr/share/doc/<package>/changelog.Debian.gz (zgrep for the id), and whether
        `apt-get -s --with-new-pkgs install --only-upgrade <packages>` would install anything (packages that are
        deferred by phasing are not an error, do not force them). The detection exits 0 when the changelog lists the
        CVE or no update is pending, and 1 only when an update is available that is not installed. Check that the
        installed package is the affected product at all (a library or plugin named like the product is not the
        product). When the package is current and nothing can be installed, the finding is a false positive or
        needs a newer release: say so in the output and exit 0 from the detection, do not leave it failing forever.
        The remediation updates the whole product (all its packages), runs `apt-get update` first and does not
        fail when the index cannot be refreshed (the device may be offline): it then works with what it has.
        A request may name the operating system and its version of the device the script runs on (Ubuntu 26.04 LTS,
        Microsoft Windows 11 Pro (10.0.26200)): write the commands for that system and version (its package manager,
        service manager, paths and PowerShell edition), not for another distribution or an older release.
        A request may list the references of the detection (advisories, vendor documentation). You cannot open them,
        so use what you know of the vulnerability or the product: when the vendor recommends a mitigation or
        workaround that needs no new version (a setting, a disabled feature, a registry value, a configuration
        line), apply it in the remediation where the update cannot be installed or is not available yet, and name
        in the description of the script which reference to check. A mitigation never replaces installing an
        available update.
        TEXT;

    private const SCHEMA = [
        'type' => 'object',
        'properties' => [
            'name' => ['type' => 'string'],
            'description' => ['type' => 'string'],
            'platform' => ['type' => 'string', 'enum' => ['all', 'windows', 'linux']],
            'timeout' => ['type' => 'integer'],
            'detection' => ['type' => 'string'],
            'remediation' => ['type' => 'string'],
            'manual_remediation' => ['type' => 'boolean'],
        ],
        'required' => ['name', 'description', 'platform', 'timeout', 'detection', 'remediation', 'manual_remediation'],
        'additionalProperties' => false,
    ];

    /**
     * The scripts of an AI answer when they can be saved: PowerShell that parses, within the size limit, the detection
     * ending with exit 0 and exit 1. Throws a RuntimeException otherwise (the AI did not return a valid script).
     *
     * @param  array<string, mixed>  $generated  what generate() returned
     * @return array<string, mixed> the same
     */
    public static function checked(array $generated): array
    {
        foreach (['detection', 'remediation'] as $field) {
            $errors = trim($generated[$field]) === '' ? [] : PowerShellCheck::errors($generated[$field]);
            if ($errors !== [] || strlen($generated[$field]) > Script::MAX_CODE_BYTES) {
                throw new RuntimeException(__('The AI did not return a valid script, try again.'));
            }
        }
        if (! PowerShellCheck::exits($generated['detection'], 0) || ! PowerShellCheck::exits($generated['detection'], 1)) {
            throw new RuntimeException(__('The AI did not return a valid script, try again.'));
        }

        return $generated;
    }

    /**
     * The script for the request: name, description, platform, timeout, detection, remediation,
     * manual_remediation. Throws a RuntimeException with a message for the user when it cannot.
     *
     * @param  array<string, mixed>  $current  the script in the form, changed instead of a new one when it has code
     * @return array{name: string, description: string, platform: string, timeout: int, detection: string, remediation: string, manual_remediation: bool}
     */
    public static function generate(string $request, array $current = []): array
    {
        $content = 'Request: '.trim($request);
        if (trim((string) ($current['detection'] ?? '')) !== '') {
            $content .= "\n\nCurrent script (JSON):\n".json_encode(array_intersect_key($current, self::SCHEMA['properties']), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        $text = AiService::ask(self::SYSTEM, $content, self::SCHEMA, 'script');
        $script = $text !== '' ? json_decode($text, true) : null;
        if (! is_array($script) || ! is_string($script['detection'] ?? null) || trim($script['detection']) === '') {
            throw new RuntimeException(__('The AI did not return a script, try again.'));
        }

        return [
            'name' => mb_substr(trim((string) ($script['name'] ?? '')), 0, 255),
            'description' => mb_substr(trim((string) ($script['description'] ?? '')), 0, 2000),
            'platform' => array_key_exists($script['platform'] ?? null, Script::PLATFORMS) ? $script['platform'] : 'all',
            'timeout' => max(5, min(Script::MAX_TIMEOUT, (int) ($script['timeout'] ?? 60))),
            'detection' => $script['detection'],
            'remediation' => (string) ($script['remediation'] ?? ''),
            'manual_remediation' => (bool) ($script['manual_remediation'] ?? false),
        ];
    }
}
