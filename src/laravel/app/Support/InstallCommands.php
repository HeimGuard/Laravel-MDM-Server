<?php

namespace App\Support;

class InstallCommands
{
    /**
     * One-liners that download the agent to a temporary file and run its installer
     * (it copies itself to the install directory, enrols the device and registers the service).
     *
     * @return array<string, string>
     */
    public static function for(int|string|null $enrolmentCode = null): array
    {
        $serverUrl = rtrim(url('/'), '/');
        $scriptUrl = config('mdm.agent_download_url') ?: url('agent/app.ps1');
        // Without a code the command only updates an existing installation (it keeps its token).
        $arguments = $enrolmentCode === null
            ? sprintf("-ServerUrl '%s' -Install", $serverUrl)
            : sprintf("-ServerUrl '%s' -EnrolmentCode %s -Install", $serverUrl, $enrolmentCode);
        // The agent pins the server key only when it has this fingerprint, a check independent of
        // where the script was downloaded from.
        $arguments .= sprintf(" -ServerKeyFingerprint '%s'", Signing::fingerprint());

        // A unique file name avoids reusing a stale download (e.g. one another user left in /tmp),
        // and "if ($?)" only runs the installer when the download succeeded.
        return [
            // Windows PowerShell 5.1, run as Administrator.
            'windows' => sprintf(
                'iwr -useb \'%s\' -OutFile ($f = "$env:TEMP\heimguard-agent-$(Get-Random).ps1"); if ($?) { & powershell -ExecutionPolicy Bypass -File $f %s }',
                $scriptUrl,
                $arguments,
            ),
            // PowerShell 7 on Windows (as Administrator) or Linux (sudo pwsh).
            'pwsh' => sprintf(
                'iwr -useb \'%s\' -OutFile ($f = Join-Path ([IO.Path]::GetTempPath()) "heimguard-agent-$(Get-Random).ps1"); if ($?) { & pwsh -ExecutionPolicy Bypass -File $f %s }',
                $scriptUrl,
                $arguments,
            ),
        ];
    }

    /**
     * Command that updates the agent already installed on the device.
     */
    public static function update(\App\Models\Device $device): string
    {
        return static::for()[$device->platform === 'linux' ? 'pwsh' : 'windows'];
    }
}
