# Installing the agent

## Installation

Click **Add device** in the portal. It shows the enrolment code (8 random characters, valid for 15 minutes; registering is limited to 10 tries a minute per client, and after 20 wrong codes in 15 minutes all open codes are dropped) and ready-made install commands with
a copy button:

- **Windows PowerShell** – run in PowerShell as Administrator,
- **PowerShell 7** – run in pwsh as Administrator on Windows, or with `sudo pwsh` on Debian / Ubuntu
  ([install PowerShell](https://learn.microsoft.com/powershell/scripting/install/install-ubuntu)).

The command downloads the agent from the server (`/agent/app.ps1`, the version matching the server)
to a temporary file and runs its installer, which copies the agent to `%ProgramData%\HeimGuard`
on Windows or `/opt/heimguard` on Linux (`-InstallPath` to change), enrols the device and installs
the service:

```powershell
iwr -useb 'https://mdm.example.com/agent/app.ps1' -OutFile ($f = "$env:TEMP\heimguard-agent-$(Get-Random).ps1"); if ($?) { & powershell -ExecutionPolicy Bypass -File $f -ServerUrl 'https://mdm.example.com' -EnrolmentCode 1234 -Install }
```

Running the command on a device where the agent is already installed only updates it: the existing
token is kept, the device is not enrolled again and no enrolment code is needed.

The installer needs administrator rights (Administrator on Windows, root via `sudo` on Linux). It
checks them first and stops without using up the enrolment code when they are missing.
 Set `AGENT_DOWNLOAD_URL` to download it from elsewhere, e.g. a GitHub release.

Manually:

```powershell
.\app.ps1 -ServerUrl https://mdm.example.com -EnrolmentCode 1234 -Install
```

Parameters:

| Parameter | Description |
|-----------|-------------|
| `-ServerUrl` | The address of the portal (the only option on the command line of the task / service) |
| `-EnrolmentCode` | The code from **Add device** (a new device only) |
| `-Install` | Install or update the agent and register the scheduled task / systemd service |
| `-InstallPath` | Install directory, default `%ProgramData%\HeimGuard` or `/opt/heimguard` |
| `-ServerKeyFingerprint` | Pin the server key only when it matches (the install commands pass it) |
| `-ResetServerKey` | Pin the current server key again (after it was replaced on purpose) |
| `-DisableScripts`, `-EnableScripts` | Remediation scripts on this device ([Remediation scripts](remediation-scripts.md#remediation-scripts)) |
| `-NetworkDiscovery off\|neighbours\|scan` | [Network discovery](networks.md#network-discovery) |
| `-PortScan off\|on` | Whether the agent scans the open ports of an address of its network when asked in the portal |
| `-SecurityLogs off\|on` | [Security scanner](security-scanner.md#security-scanner): whether the records of the security logs are sent (default off) |

Everything else is in `config.json` next to the agent, read on every start (the server cannot change
it, edit the file and restart the agent):

| Key | Default | Description |
|-----|---------|-------------|
| `report_interval` | `300` | Seconds between device reports (at least 60) |
| `heartbeat_interval` | `30` | Seconds between heartbeats with CPU/RAM (at least 10) |
| `inventory_interval` | `21600` | Seconds between update checks (at least 600) |
| `health_interval` | `3600` | Seconds between disk health (S.M.A.R.T.) checks (at least 300) |
| `realtime` | `true` | `false`: HTTPS only, without the WebSocket |

The WebSocket is always `/app` at the address of `-ServerUrl` (`wss://mdm.example.com/app/…` for
`https://mdm.example.com`), where nginx proxies it to Reverb (the Docker image does). The server only
gives the agent the key and its channel, so nothing about Reverb is set on the devices. Agents before
1.16.0 took these options on the command line (`-ReportInterval`, `-NoRealtime`, `-ReverbHost`,
`-ReverbPort`, `-ReverbScheme` …); they still work there, and `-Install` moves the intervals and
`-NoRealtime` to `config.json` and drops the `-Reverb*` ones.

The device detail shows whether the agent is connected over the **WebSocket** (heartbeat in the last
90 s), the **REST API** (report in the last 11 min) or both, the agent version, and the device type
(server, laptop or desktop, detected by the agent).

When the server serves a newer agent than the device runs, the detail shows an **Update agent** button
and the update command to copy. The button only asks the agent to update itself; the message carries
no command or data. The agent then:

- downloads `/agent/app.ps1` from the `-ServerUrl` it was installed with (never from an address in the
  message), and only over HTTPS (plain HTTP is accepted for localhost only),
- rejects a script that does not parse or is not newer than the running version (no downgrades),
- replaces itself and restarts.

Agents older than 1.1.0 cannot update themselves and have to be reinstalled via **Add device**.

The token is stored next to the script (`Token.xml` on Windows, `token` readable by root only on
Linux) and logs are written to `agent.log`. The agent only executes the commands `turnOff`, `restart`,
`doUpdates`, `installUpdate`, `updateAgent`, `runScripts`, `sync`, `wake`, `pingNow`, `scanNetwork` and `scanPorts`
(`$AllowedCommands` in `app.ps1`); any other name is answered as failed.

## Checking the agent

Windows: the agent is the scheduled task **HeimGuard-Agent** in the root of the Task Scheduler
Library. It runs as `SYSTEM`, so Task Scheduler only lists it when started as Administrator. The
installer only saves the enrolment code: the task enrols the device itself on its first start, as
`SYSTEM`, which then holds the token and the device key. The installer waits for that start and
shows whether it worked.

```powershell
Get-ScheduledTask -TaskName 'HeimGuard-Agent' | Select-Object TaskName, State   # Running
Get-Content "$env:ProgramData\HeimGuard\agent.log" -Tail 30 -Wait                # as Administrator
```

Linux: `systemctl status heimguard-agent` and `sudo tail -f /opt/heimguard/agent.log`.

**Older installations (agents before 1.32.0)** were named Laravel-MDM: the task `Laravel-MDM-Agent`, the service
`laravel-mdm-agent`, the directory `%ProgramData%\Laravel-MDM` / `/opt/laravel-mdm`. Updated to 1.32.0, the agent
moves to the new names by itself once it has reached the server: a separate task (`HeimGuard-Agent-Migrate`) or
transient unit (`heimguard-agent-migrate`) waits for it to end, copies the default directory to the new one,
registers `HeimGuard-Agent` / `heimguard-agent` from the old task / service and starts it. When the new agent does
not start within 3 minutes, the old task / service runs again (logged in `agent.log`, tried again a day later).
The new agent removes the old task / service and directory. An install directory chosen with `-InstallPath`
stays, only the task / service is renamed. The device key keeps its name (`Laravel-MDM-Agent`, a CNG key cannot
be renamed): the device stays the same in the portal. Running the install command again on such a device moves
it the same way.

If the agent cannot start (not enrolled, a token or key it cannot read), `agent.log` ends with
`Agent stopped: <reason>`. On Windows, agents before 1.7.0 enrolled as the installing
administrator, so the `SYSTEM` task could not read the token. Running the install command again
as Administrator hands the token over.

## Uninstalling

Windows (PowerShell as Administrator):

```powershell
# The names of agents before 1.32.0 too (Laravel-MDM).
foreach ($name in 'HeimGuard-Agent', 'Laravel-MDM-Agent') {
    Stop-ScheduledTask -TaskName $name -ErrorAction SilentlyContinue
    Unregister-ScheduledTask -TaskName $name -Confirm:$false -ErrorAction SilentlyContinue
}
Remove-Item -Recurse -Force "$env:ProgramData\HeimGuard", "$env:ProgramData\Laravel-MDM" -ErrorAction SilentlyContinue
# The device key (agents 1.7.0+) is a CNG machine key outside that folder.
$provider = [Security.Cryptography.CngProvider]::MicrosoftSoftwareKeyStorageProvider
foreach ($name in 'HeimGuard-Agent', 'Laravel-MDM-Agent') {
    if ([Security.Cryptography.CngKey]::Exists($name, $provider, 'MachineKey')) {
        [Security.Cryptography.CngKey]::Open($name, $provider, 'MachineKey').Delete()
    }
}
```

Linux:

```bash
# The names of agents before 1.32.0 too (laravel-mdm).
sudo systemctl disable --now heimguard-agent laravel-mdm-agent
sudo rm -f /etc/systemd/system/heimguard-agent.service /etc/systemd/system/laravel-mdm-agent.service && sudo systemctl daemon-reload
sudo rm -rf /opt/heimguard /opt/laravel-mdm
```

Agents older than 1.1.0 ran from the folder they were started in, not from the install directory.
The scheduled task shows where: `(Get-ScheduledTask Laravel-MDM-Agent).Actions.Arguments` (they had the old name); delete
that folder (`app.ps1`, `Token.xml`, `agent.log`, `inventory.json`) instead. Then delete the device
in the portal.
