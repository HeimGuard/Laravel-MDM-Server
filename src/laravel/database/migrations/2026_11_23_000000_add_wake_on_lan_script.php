<?php

use App\Models\Script;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const NAME = 'Enable Wake-on-LAN (Windows)';

    /** The wired cards wake the PC on a magic packet (Fast Startup is left as it is: Turn off of the agent is a hybrid shutdown). */
    private const DETECTION = <<<'PS'
# Wake-on-LAN of a Windows PC: its wired network cards wake the PC on a magic packet.
# Exit 0 = ready, exit 1 = something is not set. (The BIOS/UEFI setting "Wake on LAN" is not seen from here.)
$problems = @()
$adapters = @(Get-NetAdapter -Physical -ErrorAction SilentlyContinue | Where-Object { $_.PhysicalMediaType -eq '802.3' })
if ($adapters.Count -eq 0) {
    Write-Output 'No wired network card'
    exit 0
}
foreach ($adapter in $adapters) {
    $power = Get-NetAdapterPowerManagement -Name $adapter.Name -ErrorAction SilentlyContinue
    # "Unsupported": the driver has no such setting, nothing to change.
    if ($power -and "$($power.WakeOnMagicPacket)" -notin 'Enabled', 'Unsupported') {
        $problems += "$($adapter.Name): Wake on Magic Packet is $($power.WakeOnMagicPacket)"
    }
}
if ($problems.Count -gt 0) {
    Write-Output ($problems -join '; ')
    exit 1
}
Write-Output "Wake on Magic Packet is on for $($adapters.Count) card(s)"
exit 0
PS;

    /** Turns it on for every wired card (what a Windows update or a driver often resets). */
    private const REMEDIATION = <<<'PS'
# Wake-on-LAN of a Windows PC: Wake on Magic Packet on every wired network card and the card may wake the PC.
# Takes effect at once (the next shutdown keeps the card powered).
foreach ($adapter in @(Get-NetAdapter -Physical -ErrorAction SilentlyContinue | Where-Object { $_.PhysicalMediaType -eq '802.3' })) {
    try {
        Set-NetAdapterPowerManagement -Name $adapter.Name -WakeOnMagicPacket Enabled -ErrorAction Stop
        Write-Output "$($adapter.Name): Wake on Magic Packet enabled"
    }
    catch {
        Write-Output "$($adapter.Name): $($_.Exception.Message)"
    }
    # The standard keyword of the driver ("Wake on Magic Packet" in the advanced settings), where there is one.
    try { Set-NetAdapterAdvancedProperty -Name $adapter.Name -RegistryKeyword '*WakeOnMagicPacket' -RegistryValue 1 -ErrorAction Stop } catch { }
    # "Allow this device to wake the computer".
    & "$env:SystemRoot\System32\powercfg.exe" -deviceenablewake "$($adapter.InterfaceDescription)" | Out-Null
}
exit 0
PS;

    /**
     * A remediation script every installation starts with, like Allow network scans: it makes a Windows PC wake
     * on a magic packet (Windows updates and drivers often switch it off). Manual remediation: a run only detects,
     * an admin starts the remediation per device.
     */
    public function up(): void
    {
        if (DB::table('scripts')->where('name', self::NAME)->exists()) {
            return;
        }
        $now = now();
        DB::table('scripts')->insert([
            'name' => self::NAME,
            'description' => 'Makes a Windows PC wake on a magic packet: Wake on Magic Packet on its wired network cards and the card may wake the PC. A Windows update or a driver often resets it. The BIOS/UEFI setting "Wake on LAN" has to be on too (it is not seen from Windows). Run it on the PCs that do not wake; it only detects, Remediate sets it.',
            'platform' => 'windows',
            'detection' => self::DETECTION,
            'remediation' => self::REMEDIATION,
            'timeout' => 120,
            'version' => 1,
            'fingerprint' => Script::fingerprintOf('windows', 120, self::DETECTION, self::REMEDIATION),
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
