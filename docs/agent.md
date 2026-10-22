# Agent

The agent (`src/powershell/app.ps1`) runs on Windows (Windows PowerShell 5.1 or PowerShell 7) and on
Debian / Ubuntu (PowerShell 7). It runs continuously as a scheduled task under `SYSTEM` on Windows and
as a systemd service (`laravel-mdm-agent`) running as root on Linux. It keeps a WebSocket connection
open for commands, sends a heartbeat with CPU and RAM usage every 30 seconds (over the WebSocket, or
over HTTPS when the WebSocket is unavailable) and sends a device report every 5 minutes over HTTPS.

The heartbeat also carries the fast-changing state: restart pending and the states of services and
Docker containers, only when something changed, so the portal shows a failed service or a stopped
container within 30 seconds. Everything large (updates, drives, networks, disk health, service and
container details) goes with the report over HTTPS; a state too large for a WebSocket message
(10 kB) is sent over HTTPS too. The live state is stored apart from the report in a single update,
so neither overwrites the other and the newer one is shown. Queued commands are handed to the agent
exactly once, also when a report and the WebSocket take them at the same time.

Every command has a state in the portal: waiting for the device, taken, running (agents 1.8.0+
report the progress in percent where it is known, e.g. apt, Windows Update, the steps of Install
updates), then done or failed with the reason. A restart and an agent update are done when the
agent is back. Commands without news are given up after a timeout. A command that is already on its
way is never queued again: double clicks, two users or an action for all devices do not run it twice.


When the agent cannot reach the server (network outage, server down), it keeps the CPU and memory
samples, and the results of the ping-only devices it pings, in `backlog-metrics.json` /
`backlog-pings.json` (a day of samples, 10 000 pings at most, also over a restart). Once the server
answers again it sends them with the time each was taken, so the charts have no gap (agents 1.11.0+).

The agent is designed to stay out of the way:

- it runs with below-normal priority (`Nice=10` and idle I/O priority on Linux),
- CPU and RAM usage come from plain Win32 calls (`GetSystemTimes`, `GlobalMemoryStatusEx`) on Windows
  and from `/proc/stat` and `/proc/meminfo` on Linux; CPU usage is the average over the heartbeat
  interval, so nothing is sampled in between,
- the expensive update checks (Windows Update, winget and PowerShell Gallery modules, or the local apt
  cache and modules on Linux) run every 6 hours in an idle-priority process; the result is cached in `inventory.json` and reused in reports,
- disk health (S.M.A.R.T.) is read once an hour in an idle-priority process and cached in `health.json`;
  on Linux sleeping disks are skipped (`smartctl -n standby`), so the agent never spins them up.

## Feature matrix

✅ supported · ⚠️ partly (see the note) · ❌ not supported

| Feature | Windows | Debian / Ubuntu |
|---|:---:|:---:|
| **Agent** | | |
| PowerShell | ✅ 5.1 or 7 | ✅ 7 |
| Runs as | scheduled task, `SYSTEM` | systemd service, root |
| Enrolment with a code, install commands | ✅ | ✅ |
| Remote agent update | ✅ | ✅ |
| WebSocket: instant commands, heartbeat | ✅ | ✅ |
| HTTPS fallback (heartbeat, commands) | ✅ | ✅ |
| **Monitoring** | | |
| CPU and RAM (charts) | ✅ | ✅ |
| OS, uptime, user, CPU, drives, networks | ✅ | ✅ |
| Restart pending | ✅ | ✅ |
| Battery and charging | ✅ | ✅ |
| Virtual machine / container badge | ✅ | ✅ |
| Services and their state | ✅ automatic services | ✅ systemd units |
| Docker containers | ✅ when installed | ✅ when installed |
| Disk health (S.M.A.R.T.) | ✅ | ⚠️ needs smartmontools |
| **Updates** | | |
| OS updates | ✅ Windows Update | ✅ apt (phased and held back marked) |
| Applications | ✅ winget | ✅ flatpak, snap |
| PowerShell modules (PowerShell Gallery) | ✅ Windows PowerShell and 7 | ✅ PowerShell 7 |
| Users' own PowerShell modules | ⚠️ listed only | ✅ updated as the user |
| Newer PowerShell 7 release | ✅ installed from GitHub (MSI, SHA-256 checked) | ✅ installed from GitHub (.deb or tar.gz, SHA-256 checked) |
| **Commands** | | |
| Install updates | ✅ Windows Update, winget, modules | ✅ apt, flatpak, snap, modules |
| Install a single update (agent 1.8.0+, PowerShell 7 from GitHub 1.8.2+) | ✅ Windows Update, winget, modules, PowerShell 7 | ✅ apt, flatpak, snap, modules, PowerShell 7 |
| Progress and result of commands (agent 1.8.0+) | ✅ | ✅ |
| Restart / Turn off | ✅ | ✅ |
| Wake-on-LAN through another agent in the network (agent 1.9.0+) | ✅ sends and is woken | ✅ sends and is woken |
| Pings ping-only devices of its network (agent 1.10.0+, not on a battery) | ✅ | ✅ |
| **Security** (agent 1.17.0+) | | |
| Security inventory for the [scanner](security-scanner.md#security-scanner) | ✅ software, processes, ports, startup, admins, settings | ✅ the same (dpkg / rpm, snap, flatpak) |
| Security events | ✅ Security, System and Defender logs | ✅ journal or `auth.log` (sshd, sudo, useradd, usermod) |

Notes:

- Disk health is not collected on virtual machines and in containers. On Linux it needs
  `smartctl` (`apt install smartmontools`).
- On Windows the agent runs as `SYSTEM`, which cannot act as a logged-on user. Modules installed in
  a user's own profile are only listed.

Where the data comes from:

| | Windows | Debian / Ubuntu |
|---|---|---|
| Report | OS, uptime, user, CPU, battery, drives, networks, pending reboot | the same, from `/etc/os-release`, `/proc`, `df`, `ip` and `/var/run/reboot-required` |
| Updates | Windows Update, winget (any system language) | `apt list --upgradable` (phased / held back marked), flatpak (system and users), snap |
| PowerShell | outdated PowerShell Gallery modules in Windows PowerShell and PowerShell 7 (users' own ones are listed, not updated: SYSTEM cannot act as the user), a newer PowerShell 7 release | the same in PowerShell 7; users' own modules are updated as that user |
| Services | running and stopped automatic services (`Get-Service`) | running and failed units (`systemctl`) |
| Docker (when installed) | containers and their state (`docker ps --all`) | the same |
| Virtualization | manufacturer and model (Hyper-V, VMware, KVM / QEMU, VirtualBox, Xen …) | `systemd-detect-virt`, DMI (VMs and containers) |
| Power | battery level and mains power (`GetSystemPowerStatus`) | `/sys/class/power_supply` |
| Disk health | `Get-PhysicalDisk`, `Get-StorageReliabilityCounter` | `smartctl` ([smartmontools](https://www.smartmontools.org/), `apt install smartmontools`) |
| Turn off / Restart | `Stop-Computer` / `Restart-Computer` | `systemctl poweroff` / `systemctl reboot` |
| Install updates | winget (also PowerShell 7 itself), PowerShell modules, Windows Update | `apt-get upgrade --with-new-pkgs`, flatpak, snap, PowerShell modules |

The device detail has tabs for drives, updates, networks, **services** (with search, failed ones
first), **Docker** containers (only where the Docker engine is installed, not just the CLI) and
**disk health** (temperature, power-on hours, SSD wear, reallocated / pending sectors and media
errors). Virtual machines and containers get a badge with the hypervisor; disk health is not collected
or shown for them. Services, Docker and Updates have a search field. The battery shows a
charging indicator while the device is on mains power.

**Install updates** runs in the background: on Linux `apt-get upgrade --with-new-pkgs` (waits for a
running apt, installs new dependencies, removes nothing), on Windows winget, Windows Update, and on
both PowerShell module updates. On Windows winget updates one package at a time (`winget upgrade --id <id>
--exact --silent`), each with a 15 minute limit: an installer that waits for a window (`SYSTEM` has
no desktop) or for an application to close is ended with its process tree, reported as failed and
the next package goes on (agents 1.10.1+; before, one such installer stopped `winget upgrade --all`
for good). App Installer, which is winget itself, is left out. Every step with its result is written to `agent.log`, the full
output of apt / winget to `updates.log`, and the update list is collected again right after.

On Windows (agents 1.14.0+):

- **The same list as the user's `winget upgrade`:** `SYSTEM` does not see applications installed
  only for a user (into their profile). The agent therefore also asks winget in the session of the
  logged-on user (a hidden one-off task) and adds what only they see, marked **user**. Those are
  updated in that session; nobody logged on means they wait.
- **What winget is doing** is shown in the progress: the download (MB or percent), that the
  installer runs, or that it runs in the user's session, with the time so far. For example
  `winget upgrade ONLYOFFICE.DesktopEditors (5/5): running the installer (3:12)`.
- **Windows Update** installs the updates it marks as "may ask for user input" too: drivers and
  vendor packages (NVIDIA, Intel, firmware) carry that flag but install quietly (`ForceQuiet`), as
  Windows Update installs them itself. One that really needs a person fails with its result.
**Python on Linux (agents 1.22.0+):** pip packages of users (`pip --user`) are updated one at a time,
also by Install updates. When the distribution manages its Python (PEP 668, the `EXTERNALLY-MANAGED`
marker next to the standard library) pip refuses to install, so a package that came from apt
(`python3-<name>`) is updated with `apt-get install --only-upgrade`, any other one with
`pip install --user --upgrade --break-system-packages`. Without the marker it is plain pip.

**Progress per row (agents 1.22.0+):** while Install updates runs, the agent also reports which
package it is installing and which are finished (`current`, `done` of the command status). The
Updates tab shows it on the row: installing, done or queued. Steps that update a whole group at once
(apt, flatpak, snap, pipx, Windows Update, PowerShell) mark all rows of that group. The update
lists are split into sections by provider: operating system (APT / Windows Update), Python,
PowerShell (release and modules), Flatpak, Snap, Winget and Other.

On Linux the update list tells what apt would install now (a simulated `apt-get upgrade`, no
network): updates deferred by phasing and held back ones (pinned, held, or needing other packages
to change) are shown with an icon and do not count as available updates. The list is also
collected again a few minutes after packages are installed outside the agent (apt,
unattended-upgrades).

## Signed communication

Agents 1.7.0 and newer sign everything they send and accept only what the server signed. The
signatures are RSA-3072 (PKCS#1 v1.5, SHA-256) and are checked with plain .NET, which works in
Windows PowerShell 5.1 and PowerShell 7 without extra modules. HTTPS still keeps the content
private; the signatures make sure nothing was forged or changed on the way, in the database or on
the device's disk.

- **Server key:** `storage/mdm-signing.key`, created on the first start (`php artisan mdm:signing-key`
  shows its fingerprint) and never stored in the database. Keep it in the backup of the storage
  volume: without it agents accept no more updates or commands and have to be reinstalled.
  `/agent/app.ps1` is served with the public key filled in, so a new or updated agent pins it on
  its first start. The install commands also pass `-ServerKeyFingerprint`, and **Add device** shows
  the fingerprint.
- **Device key:** created on the device and never sent anywhere; the server stores only the public
  key. On Windows it is a non-exportable CNG machine key (with a DPAPI-protected file as a fallback),
  on Linux a root-only file.
- **Requests:** every request of the agent is signed with the device key over the method, path,
  time, a one-time nonce and the body hash. The server rejects unsigned, changed, replayed and stale
  requests (more than 5 minutes off; the agent corrects its clock from signed server responses).
- **Responses:** every response is signed with the server key for exactly that request (its nonce),
  error responses included. The agent ignores anything else.
- **WebSocket:** a command event is only a trigger signed by the server. The agent then takes the
  commands over the signed API, so nothing can be injected or replayed over the WebSocket.
  Heartbeats sent over the WebSocket are signed with the device key.
- **Agent updates:** the agent downloads the new version into memory, checks its signature
  (`/agent/app.ps1.sig`) and only then writes it to disk. The previous version is kept: when the
  new one has not reported to the server within 5 minutes, it is restored (agents 1.10.2+).

The agent keeps the pinned key and its settings in `config.json`, which only `SYSTEM` / root and
administrators can read. The server has no way to change this file. Agents older than 1.7.0 keep
working, but they only get **Update agent**. After updating, the agent pins the key embedded in
the new version and registers its device key once. The device detail shows **Signed** or
**Unsigned agent**. System admins can reset a device key, for example after the agent was
reinstalled with a new key. `MDM_REQUIRE_SIGNED_AGENTS=true` rejects unsigned agents entirely;
they then have to be reinstalled.
