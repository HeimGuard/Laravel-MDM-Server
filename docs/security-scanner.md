# Security scanner

## Security scanner

**Security** in the main menu is a small vulnerability scanner and SIEM: agents 1.17.0+ collect a
security inventory and the raw records of their security logs every hour (and with **Sync**) in a
background job at idle priority. The agent does not interpret the logs: **parser rules** on the
server turn their records into events, **detection rules** look at the inventory and the events and
open a **finding** for every item they match.

**The logs are optional and off by default** (they contain user names, addresses and commands):
the agent sends them only when `security_logs` is `on` in its `config.json` (`-SecurityLogs on` at
install). The portal cannot switch it on; the **Allow security logs** script (like **Allow network
scans**: a run only detects, **Remediate** is yours to start on the devices you pick) does it for
you. System admins can turn the logs off for all agents. The **Agent** tab of a device lists the
features of its agent (remediation scripts, network discovery, security inventory and logs, disk
health, Wake-on-LAN, pings) as On / Off, read only. The inventory (software, ports, settings) is
sent either way.

The agent sends the inventory as **deltas**: for each source (software, processes, ports, startup,
administrators, settings) it keeps the state (a hash of the hashes of its items) the server last
acknowledged and sends only the items that changed, or just the state when nothing did (a
collection with one new package is under 1 KB instead of 20 KB or more). The server applies all
deltas of a collection in one transaction and checks that the result has the state the agent
computed; a delta that does not follow what the server has is refused (`409`, nothing stored) and
the agent sends that source whole. A collection sent twice (`collection_id`) is processed once.

Logs are not sent whole either. The agent keeps a **position per log** (the journal cursor, the
`EventRecordID` of a Windows log, the byte offset of `auth.log`) and reads on from where the
server last took them, so nothing is lost or sent twice. The server tells it **what to send**
(`GET /api/device/security/policy`): the identifiers (`sshd`, `sudo`, ...) and event ids (4625,
7045, ...) the enabled parsers read, so a busy log is filtered at the source and only the
records a parser can use leave the device (a parser without a `when` on them asks for the whole
log). Records go as columns (field names once), the body is gzipped (`Content-Encoding: gzip`,
signed over the compressed bytes, inflated by the server only after the signature checked out and
within a limit), and travels like the rest of the agent's communication: signed in both
directions over HTTPS.

Taking a collection is cheap: the server only puts it into its cache (compressed and encrypted
with the app key) and answers `202`. A job on the `security` queue parses, stores and scans it (the
queue worker of the Docker image; without a worker, `QUEUE_ENABLED=false`, it runs right after the
response), one collection of a device at a time, with retries.

What the agent collects (the *sources* of the rules):

| Source | Windows | Debian / Ubuntu |
|---|---|---|
| `software` | Programs and Features (machine and loaded user profiles) | dpkg (or rpm), snap, flatpak |
| `processes` | `Win32_Process` with the user and command line (the agent's own processes left out) | `/proc` |
| `listening` | `Get-NetTCPConnection` / `Get-NetUDPEndpoint` with the process | `ss -tulpn` |
| `startup` | Run / RunOnce keys, Startup folders, scheduled tasks outside `\Microsoft\` | cron (system and users), units in `/etc/systemd/system`, `rc.local`, desktop autostart |
| `admins` | members of Administrators | uid 0, groups sudo / wheel / admin |
| `os` (agents 1.27.0+) | the operating system: `Id` `windows`, its caption and its build with the revision as the version (`10.0.19045.5555`, what the cumulative updates change) | `Id` from `/etc/os-release` (`ubuntu`), `PRETTY_NAME`, `VERSION_ID` and the kernel (`uname -r`) |
| `posture` | firewall, antivirus (Security Center / Defender), BitLocker, Secure Boot, RDP and NLA, SMBv1, UAC, Guest, auto logon, days since the last update | firewall (ufw, firewalld, iptables / nftables), LUKS, Secure Boot, `sshd -T` (root login, passwords), unattended-upgrades, days since the last apt upgrade |
| logs (raw, since the previous collection) | `windows.security` (1102, 4625, 4648, 4697, 4698, 4720, 4722, 4724, 4726, 4728, 4732, 4740, 4756), `windows.system` (104, 7045), `windows.defender` (1006, 1116, 1117, 5001, 5010, 5012): `Time`, `Id`, `Provider`, `Level`, `Data` (EventData / UserData, names without spaces) | `linux.auth`: the auth / authpriv journal (or `auth.log`, `secure`): `Time`, `Identifier`, `Pid`, `Message` |

**Backported fixes (agents 1.23.0+, Debian / Ubuntu).** Distributions fix a vulnerability without
changing the upstream version (`php 8.5.4-0ubuntu1.5` carries the fix of 8.5.12), so a CVE rule that
compares versions would report a patched package. For every dpkg package the agent therefore reads the
head (256 KB) of `/usr/share/doc/<package>/changelog.Debian.gz` (`changelog.gz`, `changelog.Debian` or
`changelog` when it is not there) and sends the CVE ids it names as the software field `FixedCves`:
`,CVE-2026-1,CVE-2026-2,` (sorted, with commas around, at most 4000 characters). The CVE rules of
detection-policy skip an item whose `FixedCves` contains `,CVE-x,`, a package that is below the fixed
version and does not name the CVE is still reported. The CVEs of a package version are remembered in
`security-cves.json` next to the agent, so only new and updated packages are read. A changelog that
names a CVE without fixing it (a note that it does not affect the package) hides it as well; rpm
packages are not read yet and stay out of the CVE rules.

**Source packages (agents 1.24.0+, Debian / Ubuntu).** A security tracker is about source packages, the inventory
lists binary ones (`libssl3t64` is `openssl`, `libglib2.0-0t64` and `gir1.2-glib-2.0` are `glib2.0`), and a name
cannot tell them apart reliably. For every dpkg package whose source package has another name the agent sends it
as the software field `SourcePackage` (`${source:Package}` of dpkg-query); a package that is its own source has none.

The built-in parsers ([`resources/security/parsers.json`](../src/laravel/resources/security/parsers.json))
make the events `failed_logon` (sshd, 4625), `sudo_failed`, `account_created` (useradd, 4720),
`admin_added` (usermod / gpasswd to sudo, wheel or admin, 4732 to Administrators),
`account_locked` (4740), `task_created` (4698), `log_cleared` (1102, 104), `service_installed`
(7045), `malware_detected` (1006, 1116) and `protection_disabled` (5001, 5010, 5012). A parser of
your own (**Add parser** on the **Rules** tab, tried on a sample line before it is saved):

```json
{
    "kind": "parser",
    "key": "custom.su-failed",
    "name": "su: wrong password",
    "source": "linux.auth",
    "when": {"field": "Identifier", "op": "eq", "value": "su"},
    "pattern": "^FAILED SU \\(to (?<target>\\S+)\\) (?<user>\\S+) on",
    "event": {"type": "su_failed", "user": "{user}", "source": "{target}", "message": "{user} failed su to {target}"}
}
```

`when` takes the conditions of detection rules on the record, `pattern` a regular expression with
named groups (on `Message`, or on `field`); `event` fills `type`, `user`, `source`, `message` and
`count` with `{group}` or `{Field}` placeholders (`{Data.IpAddress|Data.WorkstationName}` takes the
first that is set, `-` counts as not set). The first parser that matches a record makes its event;
a detection rule with `"source": "events"` and `{"field": "Type", "op": "eq", "value": "su_failed"}`
then alerts on it.

Events are grouped by type, user and source (*25 failed sign-ins of administrator from
203.0.113.7*) and kept 30 days on the **Events** tab. Findings of the inventory are resolved when the
next inventory no longer matches; acknowledging one keeps it quiet (no alert) until it is gone.
Findings of events stay open until they are acknowledged. The device shows a **Security** tab with
its findings and inventory, and an alert from medium severity; the **Security findings** alert type
of [Notifications](alerts.md#notifications-and-alerts) sends high and critical ones.

About 40 built-in rules come from
[`resources/security/rules.json`](../src/laravel/resources/security/rules.json): remote access tools,
attack and cracking tools, miners, end-of-life software, WinRAR / 7-Zip / PuTTY with known
exploited vulnerabilities, encoded PowerShell, download cradles, reverse shells, credential dumping,
programs running from temporary folders, clear-text services and open databases, suspicious startup
items, firewall / antivirus / encryption / SMBv1 / UAC / RDP / SSH settings, brute force, cleared
logs and new administrators. They follow the file with every update of the server; system admins
switch them off on the **Rules** tab or copy one to change it. **Add rule** takes a rule in JSON,
checks it while you type and shows what it would find on a device before it is saved:

```json
{
    "key": "custom.chrome-outdated",
    "name": "Outdated Chrome",
    "severity": "medium",
    "platform": "windows",
    "source": "software",
    "when": {"all": [
        {"field": "Name", "op": "starts_with", "value": "google chrome"},
        {"field": "Version", "op": "version_lt", "value": "120"}
    ]},
    "message": "{Name} {Version} is outdated",
    "remediation": "Update Chrome.",
    "urls": ["https://chromereleases.googleblog.com/"]
}
```

**Rules from a git repository.** Besides the bundled rules, a job takes rules from a public git
repository once a day (and with **Update now**): set `MDM_SECURITY_FEED_URL` (a GitHub address such
as `https://github.com/you/security-rules`, or the base address of the raw files of another host,
HTTPS only) and optionally `MDM_SECURITY_FEED_REF` (branch or tag, default `main`). The repository
has a `manifest.json` with a `version` and the SHA-256 of every file (`{"version": "2026.10.1",
"files": {"rules/apps/remote.json": "<sha256>", "parsers/linux/su.json": "<sha256>"}}`),
`rules/**/*.json` (detection rules) and `parsers/**/*.json` (parsers), each file one definition or a
list (up to 2048 files; they are fetched in chunks of 100, each chunk in its own queue job, and the last job applies them). Everything is fetched and checked against the manifest first and applied in one go: an
unreachable repository, a file that does not match its hash or a manifest that is not one changes
nothing. Invalid definitions are skipped and listed; rules of the feed that disappeared from it are
removed. What you switched on or off stays, a rule you added yourself keeps its definition (custom
over feed over bundled), and a bundled rule the feed replaced comes back when it is dropped.

- `severity`: `critical`, `high`, `medium`, `low` or `info`; `platform`: `any`, `windows` or `linux`.
- `when`: a condition `{"field", "op", "value"}`, or `{"all": [...]}`, `{"any": [...]}`,
  `{"not": {...}}` (up to 6 levels, 64 conditions). Operators: `eq`, `ne`, `contains`,
  `not_contains`, `starts_with`, `ends_with`, `matches`, `not_matches` (regular expression),
  `in`, `not_in` (a list), `gt`, `gte`, `lt`, `lte`, `version_lt`, `version_lte`, `version_gt`,
  `version_gte` (`1.2.3`, `2:1.2-3ubuntu1`), `dpkg_lt`, `dpkg_lte`, `dpkg_gt`, `dpkg_gte`, `exists`,
  `empty`, `true`, `false`. Texts are compared without case; an unknown value is neither `true` nor `false`.
- `version_*` compare the leading numbers only (the epoch and everything after the first character that is
  not a digit or a dot are ignored) and a missing part is 0, so `8.5` is `8.5.0`. A bound such as
  `7.1.2-31` is therefore read as `7.1.2`; write it as `7.1.2.31`, the way distributions number it.
- `dpkg_*` compare Debian / Ubuntu package versions the way dpkg does (`epoch:upstream-revision`, `~` sorts
  first): `8.2512.0-1ubuntu4.2` is after `8.2512.0-1ubuntu4.1`. Use them with a version an Ubuntu or
  Debian security tracker names as the fix, e.g. to skip a package that is at or after it.
- `message`: the finding, with `{Field}` placeholders of the item.
- `urls`: up to 10 `http(s)` links (documentation, MITRE ATT&CK, CVE), shown with the rule on the
  **Rules** tab and with each of its findings.
- `threshold`: one finding for the device when at least *n* items match (`{count}` and `{items}` in
  the message), e.g. more than 3 administrators.
- `unseen_days` (events only, 1 to 30): only the events whose type with the same user and source
  (`unseen_by`: `["User", "Source"]` by default, or one of them) no device had in the last *n* days,
  e.g. a sign-in of a user from an address not seen for 10 days. Compared with the events stored
  before the collection (kept 30 days).
- Each matching item is its own finding, told apart by the key fields of its source (software:
  name and source; processes: name, path and command line; ports: protocol, address and port; ...).
