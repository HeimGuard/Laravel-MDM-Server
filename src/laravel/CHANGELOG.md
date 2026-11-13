# Changelog

All notable changes of HeimGuard. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
The version is the version of the agent (`$AgentVersion` in `app.ps1`), and the Docker image of the same number
carries the server of that state: updating the image and the agents to the same version keeps them in step.
Every user-visible change adds a line under **Unreleased**; how a release is made is in `docs/releasing.md`.

## [Unreleased]

### Changed
- The open ports of a host are one card in the Overview of every device, with an agent or ping-only (table, notes of
  the scan, Scan ports), instead of a collapsed block in the device header.
- The device page shows the platform icon (Windows, macOS, Ubuntu, Debian, Linux ...) before the name of the OS.
- Documentation matches the code: what is kept how long and what is not cleaned up yet, how to back up and restore
  (the `storage` volume and the database), who may do what (no roles, no read-only role; system admins), the
  Exposed service alert, the commands the agent accepts, the `-PortScan` option.
- The three Docker Compose files use the same service and container name (`mdm`).

### Fixed
- The Changelog page (`/changelog`) shows this file; it was empty.

## [1.33.1] - 2026-11-13

### Security
- The agent protects keys and tokens before it writes them to disk, and refuses command parameters with control
  characters (#138).

### Fixed
- The agent writes its configuration, state files and its own update as a whole: a crash or power loss in the middle
  no longer leaves a half written file (#138).
- A malformed device report is answered with an error (422) instead of a server error or an empty answer (#138).

## [1.33.0] - 2026-11-13

### Added
- A collection of the agent that runs for hours (inventory, disk health, security) raises an alert of the device (#138).

### Changed
- Interface: the same buttons, cards, rows and notices on every page, readable grey text in both themes, one heading
  size, labelled icon buttons and state dots, better keyboard and screen reader support (#136, #137).

### Fixed
- The security collection of the agent failed because a function was missing in its background job (#138).
- A WebSocket connection that cannot be opened, or an installer of the PowerShell release or Chrome that hangs, ends
  after a time limit (30 s, 30 min) instead of blocking the heartbeat and the updates (#138).
- One failed command no longer drops the other commands taken from the server in the same answer (#138).

## [1.32.0] - 2026-11-12

### Changed
- The agent is installed as HeimGuard: task `HeimGuard-Agent`, service `heimguard-agent`, folder
  `%ProgramData%\HeimGuard` or `/opt/heimguard`. Older installations (Laravel-MDM) move to the new names by
  themselves when they update; if the new agent does not start within 3 minutes, the old one runs again. The device
  stays the same in the portal (#135).

## [1.31.2] - 2026-11-12

### Added
- **Explain with AI** on a security finding: what it means, how urgent it is, what to do. Only facts about the device
  and the finding are sent, nothing that names a person (#132).

### Changed
- A row of the update list shows Queued / Installing like **Install all** does, and its tooltip names the command (#133).
- An AI error names the provider and the host it came from (#133).
- On a phone the buttons of a finding and the exports stand side by side (#132).

### Fixed
- A PowerShell release that a restart finishes is not started before the restart, and the modules wait for the next
  run, so a mixed installation is not left half updated (#134).
- Without `MDM_AI_PROVIDER` the key decides the provider: an OpenAI key is no longer sent to Anthropic (#133).
- Explain with AI for a finding of another device answers "not found" instead of an AI error (#132).

## [1.31.1] - 2026-11-12

### Added
- **Syslog receiver:** the image receives syslog from other servers and devices on port 514 (UDP and TCP) and hands
  it to the log parsers; a device that sends syslog shows an icon (#116).
- **PostgreSQL:** the image supports it (`docker-compose.postgres.yml`). When `DB_CONNECTION` is changed from SQLite
  to `pgsql` the data is copied once on the next start and the SQLite file is kept as `*.bkp` (#115).
- **Icons of the containers** from dashboard-icons and selfh.st/icons in the container list; they are stored on the
  server, nothing is loaded from a CDN. `php artisan mdm:docker-icons` and `mdm:security-feed` refresh them by hand,
  also from the Jobs page (#126, #129, #130).
- The container table shows the published ports by host port and a bar of the last 12 hours (#123).
- One setting, `history_days` (System > Settings, 30 by default), for how long the history is kept (#117).

### Changed
- The history is kept as changes only, in new tables: measured values (CPU, memory, disks, battery, ping), the
  inventory (services, containers, updates, software, ...) and events. Existing data is copied by the migration
  (#117, #120, #121, #124).
- Switching between devices and opening a device is faster; only the selected tab is rendered (#117, #127).
- The Docker table shows the image under the name of the container, with an icon for the log (#114).

### Fixed
- PostgreSQL: the search of the security findings ignores case, and the checks tab counts correctly (#118, #119).
- A PowerShell release that does not start never replaces the one that does (agent 1.31.1, #131).

## [1.31.0] - 2026-11-05

### Added
- Agent 1.31.0 reads the logs of Docker containers (`docker.logs`) and of nginx, Apache, lighttpd, Caddy and Traefik
  (`web.log`) for the security checks, when the security logs are on; a container whose log is parsed has an
  indicator (#111, #112).
- The device page has an **Overview** tab and **Wake** and **Sync** buttons; every action is in the audit log (#109).
- The networks page has an overview and a detail of each network, with breadcrumbs and stat tiles (#110).

### Changed
- HeimGuard colours for the dark and the light theme (#110).
- The names PanoptiPulse and the old links are replaced by HeimGuard; the security feed comes from
  `HeimGuard/detection-policy` (#111).

## [1.30.1] - 2026-11-02

### Fixed
- On Linux the service starts the PowerShell of a snap through its `current` link, so it survives a snap update.

## Earlier versions

Agents up to 1.30.0: monitoring of CPU, memory, drives, networks,
battery, services, Docker containers and S.M.A.R.T.; updates of Windows Update, winget, apt, snap, flatpak, pip,
Google Chrome and PowerShell from the portal; commands over a WebSocket; Wake-on-LAN; ping-only devices; alerts and
notifications to e-mail, ntfy, Discord, Telegram and more; a network map with discovery of unknown devices;
remediation scripts; the security scanner with CVE matching, log parsers and a rules feed; dashboards; signed
communication between the server and the agents; six languages.
