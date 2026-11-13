# Agent API: what the agent sends, what the server keeps, where the web shows it

The contract between `src/powershell/app.ps1` and `src/laravel/routes/api.php`. Read this before changing a field on either
side; the rules for changes are in [`CLAUDE.md`](../CLAUDE.md), the storage in [data-model.md](data-model.md), the agent itself in
[agent.md](agent.md). Contract tests: `tests/Feature/AgentContractTest.php` (the shapes below), `DeviceCommandTest`,
`DeviceLiveStateTest`, `ReportInventoryTest`, `SecurityScannerTest`, `ScriptTest`, `PingValuesTest`.

## Rules of the wire

- **Signed:** every request is signed with the device key (`MDM1-REQ`), every response with the server key (`MDM1-RESP`,
  also the errors). Timestamps in signatures are unix seconds (server time, the agent corrects its clock from responses). Agents
  before 1.7.0 do not sign: the server accepts them unless `MDM_REQUIRE_SIGNED_AGENTS`.
- **JSON from PowerShell:** a list of one item arrives as the item itself and an empty list as `null` unless the agent wraps it in
  `@()` (inside a hashtable or an object the array stays an array). The server therefore reads every list through
  `ReportInventoryService::asList` / `Device::listOf` (one object = one row, null = none). Never rely on `[]` vs `null`.
- **Units:** `cpu` percent 0-100 (one decimal), memory and drive sizes bytes, `uptime` seconds, `progress` percent 0-99 while running
  (100 only with `succeeded`), `rtt` milliseconds, `at` / `first` / `last` unix seconds. Timestamps inside the report are
  not used by the server (it stamps by arrival), except the backlogs (`at`).
- **Spelling that must not be "fixed" on one side only:** the update rows of applications (`packages_updates`) use
  `Avaliable` (sic), module updates use `Available`; `os` is lower case, the rest of `machine` is PascalCase.
- **Errors the agent can see:** 401 (`clock_skew`, `invalid_signature`, `key_not_registered`, `replayed`, `signature_required`),
  403 (server without the feature or an unsigned agent), 404 (unknown command / run), 409 (`not_running`, `key_already_registered`,
  `resync` of the security delta), 413 / 422 (too large, malformed: the message is logged by the agent and shows as an alert of
  the device), 429 (only `/device/register`), 502-504 without a signature (the server is restarting: not an alert).

## Endpoints

| Agent (function) | Endpoint | Sends | Server validates / stores | Web shows |
|---|---|---|---|---|
| Report (`Send-Report`, `Get-Report`), every 5 min, also after a sync / scan | `POST /api/device` | `machine`: `AgentVersion Platform Type Virtualization{Type,Name} Hostname User os uptime last_logon_user Processor Cores Battery PluggedIn RestartRequired Drives[] Networks[] ScriptsEnabled ServerKeyFingerprint NetworkDiscovery PortScan Features{scripts,network_discovery,port_scan,security_logs}`; lists `os_updates packages_updates module_updates os_updates_error services docker{containers[],error} disk_health neighbours[]` | not an object with a `machine`: 422 `invalid_report`. `name`, `os`, `drives`, `public_ip`, `last_http_at` on the device; the rest is queued (`DeviceReportInbox` -> `ProcessDeviceReport`): `devices.data` = report without the lists, the lists are inventory (`ReportInventoryService`: `service`, `container`, `os_update`, `package_update`, `module_update`, `docker`, `disk_health`), neighbours -> `NetworkNeighbour`, disk usage -> values (`DiskUsage`), public addresses -> `NetworkSite`. Answer: `commands`, `tasks`, `scripts_pending`, `ping_targets`, `public_address_url` | Device list and detail (all tabs: info, drives, networks, updates, services, Docker, disk health, agent), network map, alerts (`SmartAlerts`, `AlertEvaluator`) |
| Heartbeat over WebSocket (`Send-Heartbeat`), every 30 s | Reverb event `client-heartbeat`, signed `MDM1-HB` | `device_id ts nonce metrics{cpu,memory_used,memory_total} state` | `DeviceUsage::sanitize` (cpu clamped 0-100, memory_used <= total) -> values `cpu`, `memory.used`, `memory.total`; `state` -> `Device::sanitizeLiveState` (`restart_required`, `power{battery,plugged}`, `services{name: state}`, `containers{name: state}`) -> live state + inventory samples | CPU / RAM charts and cards, online badge (`last_seen_at`), service / container state, battery, restart badge |
| Heartbeat over HTTPS (fallback or large state) | `POST /api/device/heartbeat` | `metrics`, `state` (only when changed) | same as above; also completes `wake` commands | same |
| Security inventory (`Send-SecurityInventory`), hourly | `POST /api/device/security` (gzip) | `collection_id`, `sources[]` (deltas: source, state, upsert, remove, or whole), `logs[]` (columnar: source, fields, rows), `cursors{source: position}` | `SecurityInbox` (16 MB), 409 `{resync: [sources]}` when a delta does not follow; 202 `{ack{source: state}, cursors}`; processed by a job (`SecurityScanner`) | Security tab of the device, Security scan pages, findings, alerts |
| Security policy (`Get-SecurityPolicy`) | `GET /api/device/security/policy` | - | `SecurityScanner::policy()` (what the parsers read) | - |
| Metrics backlog (`Send-Backlog`) | `POST /api/device/metrics/backfill` | `samples[]` of `{at, cpu, memory_used, memory_total}`, 600 at most | `DeviceUsage::backfill` (retention days, no future, no duplicates within 10 s) | CPU / RAM charts (no gap) |
| Pings of ping-only devices (`Send-PingResults`, `pingNow`) | `POST /api/device/pings` | `results[]` of `{id, up, rtt, at}`, `backlog[]` (2000 at most) | only ids the server gave in `ping_targets`; `PingUsage` values | Ping-only device pages and the network map |
| Errors (`Send-AgentErrors`) | `POST /api/device/errors` | `errors[]` of `{message, count, first, last}` | `Device::recordAgentErrors` (50, 500 chars, a few days) | "Agent errors" alert / the Agent tab |
| Port scan result (`Sync-PortScan`) | `POST /api/device/port-scan` | `ip`, `ports[]` (`port service banner server title tls ...`), `findings[]` | only when the device and the portal allow it and the address is in its networks (`PortScanResult::record`) | Network map / host page, security findings |
| Scripts (`Request-ScriptRuns`, `Update-ScriptRun`) | `GET /api/device/scripts`, `POST /api/device/scripts/runs/{id}` | result: `status` (`compliant remediated failed error rejected`), `fingerprint`, `detection_exit remediation_exit post_detection_exit`, `output` (16 kB), `error` | run must be `sent`; the fingerprint must be the run's; exit codes clamped | Scripts pages, device history |
| Key registration (`Register-MDMDevice`, `Register-DeviceKey`) | `POST /api/device/register`, `POST /api/device/key` | `enrolment_code`, `public_key{n,e}` | 10 per minute, key 2048-4096 bits, one key per device | Device "Signed" badge |
| Realtime config, trigger | `GET /api/device/realtime`, `POST /api/device/commands/take`, `POST /api/broadcasting/auth` | - | `commandResponse` hands queued commands over once | - |

## Commands and their results

`Send-CommandStatus` -> `POST /api/device/commands/{id}` with `status` (`running`, `succeeded`, `failed`), optional `progress`
(0-100, kept 0-99 while running), `message` (1000 chars), `current` (the row being installed, 300 chars, only with `running`) and `done`
(rows finished, 300 of 300 chars). The server only moves a command that is `sent` or `running` (otherwise 409 `not_running`, which
the agent treats as "given up, expected"). States of the web: `queued` (waiting for the device), `sent` (taken), `running`
(`progress` bar), `succeeded`, `failed` (the `message` is the reason), `expired` (no news within `DeviceCommand::TIMEOUTS`),
`cancelled`, `delivered` (agents before 1.7.0). Shown by `partials/device/command-progress.blade.php`, the command menu, the history
(audit log) and the update rows (`Device::updateRowState`).

| Command | Params (server checks, agent checks again) | Reported by the agent | Result in the web |
|---|---|---|---|
| `turnOff` | none | `running` "Shutting down", then `succeeded`, or `failed` with the reason | command bar, history |
| `restart` | none | `running`; the agent that starts next reports `succeeded` "Restarted" (`pending-command.json`) | command bar until the device is back |
| `doUpdates` | none | `running` with progress, `message`, `current` (`group:apt`, `winget::Id:` ...), `done`; `succeeded` "Done" or `failed` with the failures joined by `; ` (", restart required" appended) | progress and row states on the Updates tab; update list refreshed right after |
| `installUpdate` | `kind` (`windows apt winget flatpak snap module pwsh chrome pip pipx`) + `id` matching `DeviceCommand::UPDATE_KINDS`, optional `user`, `edition`, `version`, `source`, `scope` | as `doUpdates` for one row | the row's button and state |
| `updateAgent` | none (source is the install-time `-ServerUrl`) | `running` 10 / 80, the agent that starts next reports `succeeded` "Updated to X" or `failed` after a rollback | command bar, agent version badge |
| `runScripts` | none (untracked: only a trigger) | nothing; runs report through `/device/scripts/runs/{id}` | Scripts pages |
| `sync` | none | `running` 5 / 60 / 90, `succeeded` "Synced" | command bar, data refreshed |
| `wake` | `macs` (8), `broadcasts` (4), `device`, `title` | `running` "Magic packet sent ... waiting"; the server completes it when the woken device reports | command bar |
| `pingNow` | `device` | `succeeded` / `failed` with the answer time; the ping result goes to `/device/pings` | ping-only device page |
| `scanNetwork` | `cidr` of an interface of the agent, /22 or smaller | `running` progress with "N answered", `running` 95, `succeeded` after the report with the ARP table | Network map |
| `scanPorts` | `ip` (+ `ports` or `all`) in a network of the agent | `running` progress with "N open", `succeeded` after `/device/port-scan` | Host ports on the network map, findings |

## Command outcomes: the specification (proposed, not implemented yet)

**Principle.** A command is not done because the agent says it issued it, but because the web sees its effect. `wake` already
works like this (the server completes it when the woken device reports); the other commands should too. Today `turnOff` is
`succeeded` the moment the agent starts the shutdown, `restart` when the agent process is back (a restart of the agent alone
passes), and `installUpdate` / `doUpdates` when the installer says so (not that the update is gone from the list).

**States.** `queued` > `sent` > `running` > **`verifying`** > `succeeded` | `failed` (and `expired`, `cancelled` as now).
`verifying` means the agent did its part and the server waits for the proof: it is an active state, it says what it waits
for and until when. A command that the agent finishes in a way that proves nothing (`turnOff`, `restart`, the installs) ends
in `verifying`, not in `succeeded`. Old agents that report `succeeded` at once are still verified by the server.

**What the server looks at** (all of it exists today, no new data): heartbeat (every 30 s) and WebSocket, the report (every
5 min), the answer to the ping of a relay agent, the uptime of the device in the report and the heartbeat (boot time = now minus
uptime), the update lists of the next inventory.

| Command | Does | OK: the verified effect | Fails when (the text of the web) | Limit |
|---|---|---|---|---|
| `wake` | a relay agent sends the magic packet | the device reports (heartbeat or report) or the ping of a relay answers | the agent could not send it (its message); nothing within the limit: "did not come online within 3 minutes" | **3 min** after the packet (today 10) |
| `turnOff` | the agent shuts the device down | the device is silent: no heartbeat, report or WebSocket for 60 s (a failing ping where a relay pings it) | the agent refused (its message); still active after the limit: "still running 2 minutes after the shutdown command" | **2 min** after the agent confirmed (today: `succeeded` at once) |
| `restart` | the agent reboots the device | the device is back (heartbeat or report) **and its boot time is after the command** (the uptime counter was reset) | the agent refused; not back in time; back **without a reboot** (boot time before the command): "the agent restarted, the device did not reboot" | 30 min (unchanged) |
| `installUpdate` | one update is installed | the next inventory (collected right after the install) no longer lists it, or the installed version is at least the offered one | the installer failed (its message); still listed and no restart pending: "installed, but still offered" | as today |
| `doUpdates` | every offered update is installed | as `installUpdate` for each row; the result names the rows installed, failed and remaining | any row failed or remains: the rows are named (as today, ", restart required" appended) | as today |
| `updateAgent` | the script is replaced and restarted | the agent reports the target version (as today, incl. the rollback) | rolled back: "version X did not report within 5 minutes" | as today |
| `sync` | inventory and disk health are collected and reported | a report whose collection time is after the command | none arrived | as today |
| `pingNow` | the pinging agent pings now | the result arrives | no answer | as today |
| `scanNetwork`, `scanPorts` | an agent scans | the result report arrives (with the counts) | none arrived | as today |
| `runScripts` | a trigger only (untracked) | each run has its own result | | |

The limits `wake` 3 min and `turnOff` 2 min are the owner's decision. Two notes on them: a device that boots slowly (a NAS, a
server with a long firmware check) can need more than 3 minutes, so a limit per device may be needed later; the turn-off check
uses 60 s of silence and not the 90 s `Device::HEARTBEAT_TIMEOUT` of "offline", because the last heartbeat can be 30 s older than
the power-off and the shutdown takes 10-40 s: 90 s of silence could come after the 2 minutes.

**What the web shows** for every finished command (the command bar and the history): one line each of what was expected, what
was observed and, when it failed, why; the texts come from this table, not from the agent.

**Open point.** `restart` where the device comes back with an unchanged boot time: proposed as `failed` (the reboot did not
happen), not as a warning.

**Order of work.** (1) this specification; (2) the server: the table as code (`label`, expectation, limit, verifier), the state
`verifying`, the verifiers of `turnOff`, `restart`, `sync`, `pingNow` and the scans (no change of the agent), the lines of the
web, a test per command; (3) the agent: the list of installed, failed and remaining updates and the evidence it can add (a new
agent version).

## Known quirks (not defects, do not "fix" one side)

- `Avaliable` vs `Available` (above).
- Windows `Drives` is not wrapped in `@()` in the agent: one volume is an object, none is `null` (the server copes, `AgentContractTest`).
- The live state lists every service it knows: a heartbeat with `services: {}` removes all services (an agent without systemd sends none anyway).
- Job result files of the agent (`update-progress.json`, `update-result.json`) are written without the temporary-file step; the main loop
  reads them in a `try`.
