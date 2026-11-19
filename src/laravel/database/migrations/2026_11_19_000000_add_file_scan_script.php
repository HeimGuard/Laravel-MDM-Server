<?php

use App\Models\Script;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const NAME = 'Allow file scan';

    /** file_scan "on" in the agent's config.json (MDM_AGENT_DIR, agents 1.34.0+). */
    private const DETECTION = <<<'PS'
# File scan: the agent looks for programs that are not installed (portable ones) on the fixed
# drives of a Windows device when file_scan is "on" in its config.json. Exit 0 = on, exit 1 = not yet.
$dir = if ($env:MDM_AGENT_DIR) { $env:MDM_AGENT_DIR } elseif ($IsLinux) { '/opt/heimguard' } else { Join-Path $env:ProgramData 'HeimGuard' }
$path = Join-Path $dir 'config.json'
if (-not (Test-Path -Path $path)) {
    Write-Output "No agent configuration at $path"
    exit 1
}
$level = (Get-Content -Path $path -Raw -Encoding UTF8 | ConvertFrom-Json).file_scan
if ($level -eq 'on') {
    Write-Output 'File scan is on'
    exit 0
}
Write-Output "file_scan is '$(if ($level) { $level } else { 'off (default)' })'"
exit 1
PS;

    /** Sets file_scan to "on" (a backup next to it); the agent takes it with its next collection. */
    private const REMEDIATION = <<<'PS'
# Turns the file scan on: file_scan "on" in the agent's config.json. The previous file is
# kept as config.json.bak; the agent reads the setting with its next collection (hourly).
$dir = if ($env:MDM_AGENT_DIR) { $env:MDM_AGENT_DIR } elseif ($IsLinux) { '/opt/heimguard' } else { Join-Path $env:ProgramData 'HeimGuard' }
$path = Join-Path $dir 'config.json'
if (-not (Test-Path -Path $path)) {
    Write-Output "No agent configuration at $path"
    exit 1
}
$raw = Get-Content -Path $path -Raw -Encoding UTF8
$config = $raw | ConvertFrom-Json
$config | Add-Member -NotePropertyName file_scan -NotePropertyValue 'on' -Force
$json = $config | ConvertTo-Json -Depth 10
# Written only when it reads back as the same settings with the file scan on.
if (($json | ConvertFrom-Json).file_scan -ne 'on') {
    Write-Output 'The new configuration did not read back, nothing changed'
    exit 1
}
Set-Content -Path "$path.bak" -Value $raw -Encoding UTF8
Set-Content -Path $path -Value $json -Encoding UTF8
Write-Output "file_scan set to 'on' in $path"
exit 0
PS;

    /**
     * A remediation script every installation starts with, like Allow network scans: it turns the
     * file scan on for the devices it runs on. Manual remediation: a run only detects, the
     * remediation runs when an admin starts it (Remediate) on a device; nothing runs, and nothing
     * is switched on, until someone runs the script.
     */
    public function up(): void
    {
        if (DB::table('scripts')->where('name', self::NAME)->exists()) {
            return;
        }
        $now = now();
        DB::table('scripts')->insert([
            'name' => self::NAME,
            'description' => 'Sets file_scan to "on" in the agent\'s config.json, so a Windows device looks for programs that are not installed (portable ones) and reports them with their versions in the software (agents 1.34.0+). Run it on the devices that should do it; it only detects, Remediate turns the file scan on.',
            'platform' => 'windows',
            'detection' => self::DETECTION,
            'remediation' => self::REMEDIATION,
            'timeout' => 60,
            'version' => 1,
            'fingerprint' => Script::fingerprintOf('windows', 60, self::DETECTION, self::REMEDIATION),
            'manual_remediation' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('scripts')->where('name', self::NAME)->where('version', 1)->delete();
    }
};
