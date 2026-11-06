# Data model

How the data of the devices is stored, and where new data goes. The rules for working on it are in `CLAUDE.md`
("Data model"). A change of the storage changes the backend only: the templates, the texts and the API of the agent stay.

| Kind of data | Table(s) | Trait | State |
|---|---|---|---|
| Identity of a device | `devices` | | in use |
| Settings (set by someone) | `settings` | `HasSettings` | in use (`lan.*`) |
| Measured numbers | `value_definitions`, `value_samples` | `HasValue` | in use |
| Structured things a model has | `inventory_definitions`, `inventory_samples` | `HasInventory` | planned |
| What happened | `events` | | planned (from `security_events`) |
| What a user did | `activities` | | in use |
| Its execution | `device_commands`, `script_runs` | | in use |
| Alerts, notifications | `alert_events` | | in use, unchanged |
| Runtime state of a device | `device_status` | | planned |

## `devices`

Only what identifies a device and what is rarely written:

```json
{"id": 7, "kind": "agent", "name": "web01", "friendly_name": "Webserver", "os": "Ubuntu 24.04.1 LTS",
 "token": "9f2c…(sha256)", "created_at": "2026-09-29 08:18:45", "updated_at": "2026-11-05 15:42:10",
 "tags": ["servers", "docker"], "public_key": {"kty": "EC", "crv": "P-256", "x": "…", "y": "…"},
 "key_registered_at": "2026-09-29 08:20:01"}
```

`updated_at` is the time of the last report. The settings and the values are not columns (below). The report of the agent
(`devices.data`) is split: the lists go to the inventory, the numbers to the values, the rest is the machine's own data.

## `device_status` (planned)

What changes with every heartbeat, ping or message, so the row of `devices` is not rewritten (1:1):

```json
{"device_id": 7, "last_seen_at": "2026-11-05 15:42:12", "last_ws_at": "2026-11-05 15:42:12", "last_http_at": "2026-11-05 15:42:10",
 "live_state": {"restart_required": false, "power": {"plugged": true}}, "live_state_at": "2026-11-05 15:42:12",
 "public_ip": "203.0.113.7", "syslog_at": "2026-11-05 15:41:58"}
```

## `settings`

Polymorphic key-value (`HasSettings`). A ping-only device is pinged at, and an agent is woken through:

```json
[{"settable_type": "App\\Models\\Device", "settable_id": 12, "index": "lan.address", "type": "0", "value": "192.168.1.50"},
 {"settable_type": "App\\Models\\Device", "settable_id": 12, "index": "lan.prefix", "type": "1", "value": "24"},
 {"settable_type": "App\\Models\\Device", "settable_id": 12, "index": "lan.mac", "type": "0", "value": "AA:BB:CC:DD:EE:50"},
 {"settable_type": "App\\Models\\Device", "settable_id": 12, "index": "lan.via_device_id", "type": "1", "value": "3"}]
```

The model keeps `$device->ping_address`, `wake_mac`, ... as attributes (`HasDeviceSettings`).

## `value_definitions` and `value_samples`

Numbers (an enum as a code). A definition is one measured value of one model (`valuable_*`, `key`, `name`); `name` is empty
for what there is one of. `last_value` and `last_at` are the newest sample, so the current value costs no query of the
history. Samples are the history.

```json
[{"id": 4, "valuable_type": "App\\Models\\Device", "valuable_id": 9, "key": "disk.used_pct", "name": "C:", "unit": "%", "last_value": 61, "last_at": "2026-11-05 15:45:00"},
 {"id": 6, "valuable_type": "App\\Models\\Device", "valuable_id": 9, "key": "disk.used_pct", "name": "D:", "unit": "%", "last_value": 87, "last_at": "2026-11-05 15:45:00"}]
```
```json
[{"definition_id": 4, "value": 60, "recorded_at": "2026-11-05 15:40:00"}, {"definition_id": 4, "value": 61, "recorded_at": "2026-11-05 15:45:00"}]
```

Keys: `cpu`, `memory.used`, `memory.total`, `battery.level`, `disk.used_pct` and `disk.free_bytes` (named by the disk),
`ping.up`, `ping.rtt`, `ping.relay_id`. Planned: `disk.health`, `disk.temperature_c`, `updates.pending` and the numbers of
the checks. See `docs/server.md` ("Measured values").

## `inventory_definitions` and `inventory_samples` (planned)

The same shape as the values, but the content is structured (JSON). It generalizes `security_inventory_items`; the security
scanner reads it. A definition is one thing of one model: `key` is the kind, `name` which one. `last_data`, `last_hash`
and `last_at` are the current state; samples are the history, **only changes** (a different hash). A thing that
disappeared has a sample with `data = null` and `last_data = null`.

| Values | Inventory |
|---|---|
| `valuable_type`, `valuable_id` | `inventorable_type`, `inventorable_id` |
| `key` (`disk.used_pct`) | `key` (`service`, `container`, `process`, `port`, `open_port`, `user`, `startup`, `software`, `update`, `os`, `posture`) |
| `name` (`C:`) | `name` (`nginx`, `tcp\|0.0.0.0\|443`, `alice`) |
| `last_value`, `last_at` | `last_data`, `last_hash`, `last_at` |
| `value_samples`: `value` | `inventory_samples`: `data`, `hash` |

```json
[{"id": 8120, "inventorable_type": "App\\Models\\Device", "inventorable_id": 26, "key": "service", "name": "nginx",
  "last_data": {"DisplayName": "A high performance web server", "State": "running"}, "last_hash": "9d21…", "last_at": "2026-11-06 09:05:00"},
 {"id": 8433, "inventorable_type": "App\\Models\\Device", "inventorable_id": 26, "key": "port", "name": "tcp|0.0.0.0|443",
  "last_data": {"Protocol": "tcp", "Address": "0.0.0.0", "Port": 443, "Process": "nginx", "Path": "/usr/sbin/nginx"}, "last_hash": "77be…", "last_at": "2026-11-06 09:05:00"},
 {"id": 9001, "inventorable_type": "App\\Models\\Device", "inventorable_id": 26, "key": "posture", "name": "",
  "last_data": {"FirewallEnabled": false, "DiskEncrypted": false, "AutomaticUpdates": true, "SshRootLogin": "prohibit-password", "UnencryptedMounts": ["/"]},
  "last_hash": "e4b1…", "last_at": "2026-11-06 09:05:00"}]
```
```json
[{"definition_id": 8120, "data": {"DisplayName": "A high performance web server", "State": "running"}, "hash": "9d21…", "recorded_at": "2026-09-29 08:20:00"},
 {"definition_id": 8120, "data": {"DisplayName": "A high performance web server", "State": "stopped"}, "hash": "c4f0…", "recorded_at": "2026-11-02 02:14:09"}]
```

- The lists of the report of an agent are inventory too (`ReportInventoryService`), in the order of the report (`position`):
  `service` (named by its `Name`), `container` (by `Name`; `docker` is the one definition with the error, no `docker` means no
  Docker), `os_update`, `package_update`, `module_update` (named by a hash of the row), `disk_health` (one record with the
  disks). `devices.data` keeps the rest of the report (`machine`, ...), `$device->data->services` and the accessors read the
  lists from the inventory in the shape they were sent. The ARP table (`neighbours`) is not kept. `Device::loadSections()`
  reads them for many devices in one query.
- The states of services and containers the agent sends with its **heartbeat** (name => running / stopped / failed, ...) are
  written to the same inventory (`ReportInventoryService::applyLiveStates`): what changed is a sample, so a service or a
  container has a history of its state (when it stopped). A thing that is not in the states is gone, one that is not known
  yet is added with its name only, a changed one loses the status text of the report, and the states of containers clear the
  error of `docker`. `devices.live_state` keeps what is not inventory (`restart_required`, `power`).
- Users (`key = user`): local accounts with `"Admin": true|false`. The agent reports administrators only, so the rows are
  those until it reports all accounts. The rules of the feed use the source `admins`: it reads the users with `Admin`.
- What runs on a port is in the data of the port (`Process`, `Path`; of a scanned port `Service`, `Server`, `Title`, `Banner`).
- The result of a port scan splits: the open ports are inventory (`open_port`) of the scanned address
  (`NetworkNeighbour`), the passive observations are events (`source = portscan`). `port_scan_results` goes away.
- The inventory the security scanner uses (delta synchronization with the agent, `security_inventories.states`) is
  unchanged for the agent: the server writes a sample when the hash differs.

## `events` (planned)

What happened, also without a user. Everything that not every event has is in `data`:

```json
[{"id": 5501, "eventable_type": "App\\Models\\Device", "eventable_id": 7, "type": "failed_logon", "severity": "warning",
  "source": "syslog", "message": "Failed SSH sign-in of root from 203.0.113.9", "count": 12, "occurred_at": "2026-11-06 09:04:12",
  "data": {"parser": "org.syslog-sshd-failed", "user": "root", "origin": "203.0.113.9"}},
 {"id": 5502, "eventable_type": "App\\Models\\Device", "eventable_id": 7, "type": "went_offline", "severity": "info",
  "source": "server", "message": "No heartbeat for 90 s", "count": 1, "occurred_at": "2026-11-06 09:10:00", "data": null}]
```

`source` is where it came from (`linux.auth`, `docker.logs`, `web.log`, `syslog`, `windows.security`, `agent`, `server`,
`portscan`), `data.parser` the key of the parser that made it. It grows out of `security_events` (`source` there is the address
the attack came from: it becomes `data.origin`). `alert_events` stays as it is: an alert has a lifetime, it belongs to the
notifications.

### Two sources report the same thing

A device can report the same event twice: an agent that reads the auth log (`linux.auth`) and also forwards it as syslog
(`syslog`) gives the failed sign-in once from each. Today that is two events, so the counts the rules look at are doubled.
With `events` the second report is not a second event, it **confirms** the first:

- The events are the same when the owner, `type`, `data.user` and `data.origin` are the same and their times overlap
  (about a minute).
- `count` is not summed (the same sign-ins are counted once: the larger of the two counts), and `data.sources` lists
  every source that reported it: `{"sources": ["linux.auth", "syslog"], "verified": true}`; `source` stays the first one.
- `verified` is true when two sources that do not depend on each other reported it. A later change of the pages can show it
  as a mark or the icon of the second source; the data is there before that.

The syslog parsers of `detection-policy` (`org.syslog-sshd-*`) stay as they are: the merge, not a filter, takes care of the
duplicates, and an event only one source reports is still an event.

## Actions

`activities` is the log of what a user did (`affected_*`, `actor_*`, `lang_text`, `data.action`, `data.command_id`); the
execution of a command is `device_commands`, of a script `script_runs`. No new table.

## Open

- Raw logs: they are not stored today (only the events). A table for them would be separate, with its own retention.
