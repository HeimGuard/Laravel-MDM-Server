# Changelog

All notable changes of HeimGuard. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
The version is the version of the agent (`$AgentVersion` in `app.ps1`), and the Docker image of the same number
carries the server of that state: updating the image and the agents to the same version keeps them in step.
Every user-visible change adds a line under **Unreleased**; how a release is made is in `docs/releasing.md`.

## [Unreleased]

### Changed
- Network map: a network of one device is not drawn (no frame, no node), only the device, with the name of its network after its address on its card.

### Changed
- Network map: a public address without devices that nobody has been behind for a week is not on the map any more (it stays stored and is shown again when a device is behind it).
- Network map: the links that come into the frame of a network (the bars of the network, the link of a device to its other network such as a VPN) bend 16 px above its top edge, the cables of the devices hung from a gateway 16 px under it.
- Network map: the colours of the ports match the legend: it has 10M (orange) too, and the ring for bad packets is red on every port (it was orange, like 10M) and in the legend, on a port of the colour of its speed.

### Fixed
- SNMP: a router read over SNMP (the Turris) showed 15 ports instead of its 6: every Ethernet interface counted, with the ports of its CPU, bridges, VLANs and Wi-Fi. The names of the interfaces are read
  now (shown after the number of the port), the virtual ones (bridges, VLANs, Wi-Fi, tunnels, containers) and the CPU (`eth0`… of a router that names its ports `lan0`…) are left out, and the SNMP window lists all
  the interfaces with a tick for the ports: any can be switched on or off.

### Fixed
- Network map: a gateway with several networks (a router with a guest network) no longer draws them over one another: the one with the devices hung under the gateway is under it, the others stand right of it, each in a frame,
  and the gateway reserves the room of all of them.

### Changed
- Network map: every open network (a VPN aside) looks the same: no node of its own, its devices are in a dashed frame with the name of the network and the number of its devices (and how many are offline) on the
  bottom edge, linked from what is above it (the gateway or the public address). A click on the name closes the network (it is the node again), a click on the node opens it. Before, only the network of a gateway
  with its ports read had the frame.

## [1.40.2] - 2026-11-24

### Changed
- Network map: the devices plugged into the ports of the gateway hang under its card in one row centred under it (a switch with its devices brings them under it) and are in the frame of the network,
  which has more room inside. They no longer have a long cable across the map.

### Added
- Network map: a button **Download** (the arrow under the zoom buttons) saves the map as a PNG picture of its real size (up to twice the pixels, 16000 px at most) with every network open, whatever the window shows.

### Changed
- Network map: a network with devices that are offline says how many (`15 devices · 3 offline`) and is orange: its node, and its frame and name when it is open.
- Network map: the pointer on a cable (not only on a port) opens the dropdown of its port and lights the cable and the devices it leads to.
- Network map: the pointer on a port of a switch (or of the gateway) lights its cable and the devices it leads to and dims the rest of the map.

### Fixed
- Network map: a router that is the gateway and whose ports are read (SNMP, TP-Link) has its ports on the gateway card, like a switch among the devices (they were only on the
  card of its page). A click on a port opens the devices of the network to plug into it, and the devices plugged into its ports are linked from the port. The network of such a gateway has
  no node of its own: the gateway stands for it and the devices hang from it, in a dashed frame with the name of the network and the number of its devices; a click on the name of the frame closes the network (it is the node of the network again, a
  click on it opens it). The ports of a switch card are in one row (up to 26). A device with a cable from a port has only the cable, not also a link of
  the network: it has a column of its own at the edge of the frame, in the top row, where the cable comes straight down into it. The card of a switch no longer leaves a gap under it where its links start (its ports wrapped one under another while it was measured).
- Agent 1.40.2: the update to 1.40.1 was refused ("the downloaded agent is not valid"): three `foreach` variables of the SNMP code were typed, which PowerShell does not parse.

## [1.40.1] - 2026-11-24

### Added
- **SNMP** (agents 1.39.0+): a new item in the menu of a ping-only device, next to Ping settings (the icon of syslog), where you enter only the **SNMP community** (v1 and v2c, the agent
  tries both); the portal finds out the rest: the version, what the device is (name and description in the window), its ports (link and speed, bad packets, packets sent and
  received, on the card, the network map and in the alerts) and, when it has the MAC table, **which device is plugged into which port** (automatically, by the MAC address: what is
  set by hand stays, a device taken off a port is not paired again, a trunk to another switch is skipped).
- **Sync** of a ping-only device (agents 1.40.0+) also reads its SNMP / TP-Link switch values at once, without waiting for the minute between two readings, and the message under the device card says how it went (`SNMP v2c answered`, or why the reading failed).
- **TP-Link** in the menu of the device too, next to Ping settings, only for a device with a TP-Link MAC address: the user name and the password of an Easy Smart switch are entered
  there, no more in the Ping settings.
- Remediation script **Enable Wake-on-LAN (Windows)** (every installation starts with it, manual remediation): detects wired network cards whose
  *Wake on Magic Packet* is off (a Windows or driver update often resets it, the magic packet is sent but the PC does not wake) and **Remediate**
  switches it on (card power management, the driver keyword, the card may wake the PC). Fast Startup and the BIOS are left alone.

### Added
- **Add parser** of a syslog message no parser reads asks the AI of the server (when it is set up): it writes the parser (the fixed text, the part that varies read with
  named groups, the event type, user and source) and the editor shows it with the result on the message. Only the text of the message, the program, the facility
  and the severity are sent, not the host name; **Write the parser with AI** asks again.

### Changed
- The card of the ports of a switch: after the number of a port the devices plugged into it are badges that link to them.
- Network map: a network that has a switch whose ports are read is open at first, and the links from the ports to the devices are animated by the
  speed of the link (the dashes run from the port to the device: 1000 Mb/s fast, 100 Mb/s slower, 10 Mb/s slowest; none without a link).

### Fixed
- SNMP (agents 1.40.1+): reading a device failed at once with `Cannot convert the "System.Collections.Hashtable" value ... to type "System.Int32"` (a variable of the answer
  had the name of the version parameter, and PowerShell does not tell the case).
- Network map: the dropdown of a port of a switch stays while the pointer moves onto it (it vanished at once), the devices in it are links and
  it has a button for the devices on the port.
- Network map: a router that is the gateway of a network and also a device of the portal (a ping-only router) is one node, the gateway, not
  also a card among the devices. Plugged into a port of a switch, it has the tag *port N* and the tooltip on the gateway.

### Added
- Network map: the card of a switch whose ports are read (TP-Link Easy Smart, Ping settings) shows its ports as buttons coloured by the speed
  of the link, with a ring for bad packets. A click on a port opens a multiselect of the devices of the network that have no port yet, to tick the
  ones plugged into it by a cable; their cards get a tag *port N*.
  A port shows a dropdown on hover (link, bad packets, packets sent and received, the devices plugged into it), and the ports are in the legend of the map.
  The devices plugged into a port hang under the card of the switch, each with a link from its port in the colour of the link.
- Smart alert *The port it is plugged into has no link* for a device plugged into a port of a switch: when it is offline (check the cable) and
  when it still answers pings (the cable is probably in another port, with the action *Remove the port*).

## [1.37.0] - 2026-11-23

### Added
- Switch without SNMP (TP-Link Easy Smart): in the Ping settings of a ping-only device choose the switch and enter its user name and
  password, and the device shows its ports (link and speed, bad packets, packets sent and received). The agent of its network is the
  ingress proxy of the server (agents 1.37.0+): it makes the HTTP requests the server gives it to that device only, the server reads
  the page. The password is stored encrypted and goes to the agent encrypted with its own key.

## [1.36.2] - 2026-11-23

### Added
- Security tab of a device and its Events box: shown for every device that has events or sends syslog (before only with an inventory or a
  ping-only device), with the latest events on top. A syslog message no parser reads has an "Add parser" button (system admins, here and in
  Security → Events) that opens the parser editor with a parser for that message.

### Changed
- Syslog: a message that no parser reads is kept in the cache for a day and shown in the table of the events as an event of the type "syslog"
  (Security → Events and the Security tab of the device), so you can see what a device sends and write a parser for it. It is not stored in the database.

### Fixed
- Syslog of a ping-only device (a host without the agent) was stored, but never shown: its Security tab now lists the events made of its
  syslog, although it has no inventory.
- A winget update that ended with exit code 0 (or "nothing to update") but left the update in the list (the program was running, a per-user
  installer did not replace it) looked like a success and kept showing with no error. Agents 1.36.1+ ask winget again after each package
  (`winget list --upgrade-available --id ...`, in the session of the user for a per-user package): a package that is still offered fails the
  update with "still offered after the update (exit N), <end of the winget output>", which is in the Updates history and in the server log. When a
  restart is pending (files in use are replaced at the next start) it is not an error: the update ends as "restart required" (agents 1.36.2+).
- A package whose files were replaced after it was installed (a vendor `.deb` without a repository whose binary was updated in place, e.g. rclone
  1.69.1 in dpkg, 1.76.0 running) was reported as vulnerable by the version dpkg recorded. Agents 1.36.0+ check running packages without a source of
  updates with `dpkg --verify`; their CVE findings are now "files changed outside the package manager, version not verified", at most low.
- Devices page: the device list had its own scrollbar next to the one of the page (sticky list with a maximum height); the page is the only
  thing that scrolls now.
- The old icon stayed in the installed app (PWA): the manifest icons have a version in the URL, and the login page on a
  phone shows the current icon instead of the old `storage/images/logo.png`.

### Added
- What fails on a device is also in the server log (`laravel.log`, the Logs page), to read and send for analysis in one place: every error the agent
  logs (`Agent error on device 12 (name, agent 1.36.0): message (x3)`) and every command that fails (`Command doUpdates (87) failed on device 12 ...: reason`,
  the command itself keeps the first 1000 bytes of the reason, the log up to 4000).
- **File scan** (agents 1.34.0+ on Windows, 1.35.0+ on Linux; off by default: `file_scan` is `on` in `config.json`, `-FileScan on` at
  install): the agent also looks for programs that are not installed (portable ones) and lists them in the software as
  `portable` (Linux crates: `cargo`) with their `Path`, so the vulnerability checks see them. **It never runs what it finds**: the
  version comes from the resources and the name of a file or from text files next to it. Windows: executables of the fixed drives
  in a folder that no entry of Programs and Features owns (the walk is limited and cached). Linux: AppImages, directories of
  `/opt`, executables of `/usr/local/bin` and the users' bin directories, and cargo crates that no package owns. Script
  **Allow file scan** (manual remediation) turns it on.
  The vulnerability scanner judges them by their version like any other software and tells to replace the files (no package manager updates them).
- Script **Docker apt repository** (Linux, manual remediation): adds the Docker apt source and signing key when Docker is installed
  and no source names the release (lost in a distribution upgrade), so that apt updates docker-ce / containerd.io again.

### Changed
- Scripts are listed newest first, and the search finds every word you type, in any order and without regard to case.
- Remediation scripts written by the AI no longer need a network: the prompt says that scripts have none (no `apt update`,
  no downloads, no installing packages; updates come from the Updates tab) and that PowerShell is not a POSIX shell.
- The remediation of a vulnerable package without a source of updates (e.g. containerd.io after the Docker repository
  was lost in a distribution upgrade) names the fixed version and the steps: restore the vendor repository, then
  `apt install --only-upgrade`.
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
