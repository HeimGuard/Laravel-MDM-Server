# Devices: tags, Wake-on-LAN, ping-only

## Tags

Devices get tags (**Edit tags** in the device menu, comma-separated, e.g. `servers, family`). The
device list shows them and filters by a tag with one click. Scheduled scripts and alerts target
tags, so a newly tagged device is included without changing them.

## Wake-on-LAN

A magic packet is a broadcast in the local network, so the server (usually somewhere else) cannot
send it. **Wake** on an offline device asks another agent in the same network to send it, e.g. the
NAS or Raspberry Pi that is always on:

1. The agents (1.9.0+) report the prefix length of their addresses, so the server knows their
   networks. The sleeping device's MAC addresses (wired first, then Wi-Fi) are kept from its last
   report.
2. The relay is an online, signing agent 1.9.0+ with an interface in the same IPv4 network. When
   both reported through the same public address, it has to be the same one (the address the
   server sees, the first `X-Forwarded-For` hop behind a proxy).
3. The relay gets a `wake` command with the MAC addresses and the broadcast addresses (the network's
   own one and `255.255.255.255`) in its signed command response. It checks them again and sends the
   packet to UDP ports 9 and 7, from the interface with an address in that network (agents 1.13.3+:
   bound to its address, on Linux also to the interface), not the one of the default route (Docker,
   VPN, a second card); the command says which interface it used. The command is the relay's (its history shows it); the woken device
   shows it like its other commands, with a progress bar.
4. The command runs until the woken device reports (agents 1.13.2+; older ones are done once the
   packet is out). When it does not come online within 10 minutes, the wake fails and the woken
   device shows an alert with **Try again**.

Devices on a battery (laptops) move between networks: their last report may show a network they
have left, and the same private address (`192.168.1.0/24`) can be another network elsewhere. They
only send a magic packet when no stationary agent can and they reach the server through the same
public address as the sleeping device. Only addresses of the same family count (a network can reach
the server over IPv6 from one device and over IPv4 from another). Every relay needs a report from the last 11 minutes, so its
networks are current.

**Wake-on-LAN settings** in the device menu set the MAC address and the IPv4 address with its
prefix by hand, for a card the agent does not report well (a docking station, another card than the
one that wakes); empty fields use what the agent reports. **Wake** is in the device menu of every
physical device (not of virtual machines) and says why it cannot wake it now.

The button says why it cannot wake a device (no known network card, no relay in the network). The
device has to allow it: Wake-on-LAN enabled in the BIOS / UEFI and for the network card (on Windows
in the adapter's *Power Management* and *Advanced* settings), usually only over a cable. **Turn off** on Windows shuts down like the Start menu does (`shutdown /s /hybrid`, agents
1.17.1+), so it wakes the same way as a PC turned off by hand; a full shutdown (`Stop-Computer`,
`shutdown /s`), which older agents did, leaves many network cards unable to wake it. If it still
will not wake, check the card's *Wake on Magic Packet* / *Shutdown Wake-On-Lan* setting, the BIOS
(ErP / Deep Sleep off) and that it is on a cable. It does not cross VLANs or routers.

A Windows update or a driver update often switches *Wake on Magic Packet* of the card off again: the wake is sent (the relay's command says so) but the PC does not
come online. Every installation comes with the remediation script **Enable Wake-on-LAN (Windows)** (manual remediation, see
[Remediation scripts](remediation-scripts.md)): a run detects wired cards whose *Wake on Magic Packet* is off, **Remediate** on the device sets it
(`Set-NetAdapterPowerManagement`, the driver's `*WakeOnMagicPacket`, `powercfg -deviceenablewake`). Fast Startup is left alone (**Turn off** is a hybrid
shutdown); the BIOS / UEFI setting is not seen from Windows. The script was not run on a device by the author (no PowerShell in the cloud session).

## Ping-only devices

A printer, a NAS or a PC without the agent: **Add device** › **Ping only** with a name, the IPv4
address, the prefix of its network (`/24`) and optionally the MAC address for Wake-on-LAN.

- An online agent 1.10.0+ in the same network (an interface in that IPv4 network, a report from
  the last 11 minutes) pings it with every heartbeat (30 s, 1 s timeout). Agents on a battery never
  ping: in another network the same address may answer for another machine. Agents 1.13.4+ ping
  from the interface in the device's network (the system `ping` with `-I` on Linux, `-S` with its
  address on Windows), not over the default route (Docker, VPN, a second card); **Sync** says which
  interface answered.
- **Another subnet** (a device behind a router, e.g. 192.168.2.1 while the agents are in
  192.168.1.0/24): ICMP goes over the gateway. Automatic (the default): without an agent in its
  network the stationary agents of one site ping it, each site in turn for 5 minutes (this
  server's first) until one gets an answer; it then stays with that agent (the same private
  address can be another device at another site). **Ping through** in its ping settings picks the
  agent instead; nobody else pings it while that one is offline.
- The ping-only devices of a network are spread over all agents that can ping them: each goes to
  the one with the fewest so far, and stays with its agent while that is as good. When an agent goes
  offline, the others take its devices with their next ping (32 per agent at most).
- The agent gets the addresses with its report response and sends the results to
  `/api/device/pings` (signed like every request). The server takes results only for the devices
  it gave that agent. An answer is the heartbeat of the ping-only device: online, the round trip
  time and the agent are shown; no answer for 90 seconds means offline.
- **Wake** works as for agents: an agent of its network sends the magic packet to its MAC.
- **Sync** in its menu pings it now: the agent that pings it (1.12.0+) gets a `pingNow` command,
  pings the address it was given for it (2 s timeout) and reports the answer, e.g. `192.168.1.50
  answered in 2 ms`, shown under the device card.
  **Sync also reads it now** (agents 1.40.0+): when the server wants something read from the device (SNMP, the web page of a TP-Link switch), the agent does it
  at once, without waiting for the minute between two readings, and the message of the command says how it went: `SNMP v2c answered`, or `reading it failed: no
  answer from the device (the community, the version, or SNMP is not allowed from this agent)` (the command then fails, the message is under the device card).
- The detail has a monitor in the style of [Uptime Kuma](https://github.com/louislam/uptime-kuma):
  the last 50 pings as a bar (green answered, red not), the response now and on average, the
  uptime of the last 24 hours and 30 days, and the response time over 1 h, 24 h, 7 d or 30 d with
  the unanswered periods marked. Every ping is stored as measured values (`ping.up`, `ping.rtt`, see [Measured values](server.md#measured-values)) and kept as long as the history setting says (`history_days` in System > Settings, 30 days by default, cleaned daily): with fewer days the 30-day views are shorter.
- In the device list it has a network icon; alerts offer the status only (offline for *n* minutes). Its address, prefix and MAC are changed in
  **Ping settings** in the device menu.

### Switch without SNMP: ports (agents 1.37.0+)

A ping-only device can be a **switch** whose ports the portal shows although it has no SNMP (TP-Link Easy Smart, the way
[check_tplink](https://github.com/Clete2/check_tplink) reads them). It has its own item in the menu of the device, **TP-Link**, next to **Ping settings** (`Livewire\TplinkSettings`), offered only for a ping-only
device whose **MAC address is one of TP-Link** (the vendor of its prefix, `SwitchService::isTpLink`) or one that is read this way already. Enter the
user name and password of the switch (a blank password keeps the stored one, an empty user name stops reading it; the device stays). One way per
device: SNMP or the web page, the last one saved replaces the other.

- **The agent is the ingress proxy of the server into its network.** The agent that pings the device gets a `probe` with its
  target: the HTTP requests (steps) to make to that device, the same ones the web interface makes (`GET /PortStatisticsRpm.htm`,
  and `POST /logon.cgi` first when the switch is not logged in: the first time and every 10 minutes, or after an answer without
  ports). It makes them every minute while the device answers pings, and returns the body of the last answer. It does not read
  it: the server finds the ports in it (`SwitchService::parse`), so another page or model is a change of the server only.
- **Limits of the proxy** (`Invoke-LanProbe`): only the device it was given, only HTTP (GET, POST, default port 80), a path of
  letters, digits and `._~/%-`, no `..`, no redirects, 4 steps of 5 seconds, 256 KB of the answer, 4 devices at a time.
  It is not a general proxy into the network.
- **The password** is stored encrypted (`APP_KEY`) and never shown again. It goes to the agent only inside the step, encrypted with
  the public key of that agent (RSA-OAEP), so only it can read it (nobody on the way, no other agent). Use a password made for
  this switch: its web interface has no TLS and trusts the address of the agent after the login.
- **What is stored:** measured values of the device (`switch.port.link` with the port as name, `switch.port.bad`, `switch.port.tx`
  and `switch.port.rx` (good packets, at most every 10 minutes), `switch.ports.up`), kept as long as `history_days`. The device
  detail has a card with the ports (link and speed, bad packets, packets sent and received); after the number of a port the devices plugged into it are badges that link to them.
- An agent older than 1.37.0 ignores the `probe`: the ping works, the ports stay empty.

#### SNMP (agents 1.39.0+)

The same card for any device with SNMP v1 or v2c. It has its own item in the menu of the device, **SNMP**, next to **Ping settings** (the icon of
syslog, `fas fa-stream`, `Livewire\SnmpSettings`): enter only the read-only **SNMP community**; the portal finds out the rest itself. Empty turns it
off (the device stays). The window shows what the device says about itself once it is read.

- **Discovery:** the agent walks the standard columns and the server reads what the device is from the answer. The OIDs are the same on every
  make: SNMPv2-MIB (`sysDescr`, `sysName`, RFC 3418), IF-MIB (the interfaces, RFC 2863), BRIDGE-MIB and Q-BRIDGE-MIB (the MAC table, RFC 4188 / 4363);
  only what is vendor specific (PoE, temperature, VLAN names of one make) would need its own OIDs, and is not read. What is found out:
  - **The version:** the agent tries v2c, then v1 (v1 has no GetBulk: it walks with GetNext). The one that answered is remembered (cache, a day)
    and sent as `snmp.version` (0 = v1, 1 = v2c) so the agent tries it first; the answer carries `version`.
  - **What it is:** name and description (the inventory `snmp` / `system`: `name`, `descr`, `version`, only the changes), shown on the card.
  - **The ports:** the Ethernet interfaces (`ifType` 6) that are no virtual ones; the port is the ifIndex and the name is `ifName` (else
    `ifDescr`), shown after the number on the card and in the dropdown of the port. A Linux router (Turris, OpenWrt) reports everything of the type Ethernet:
    its ports (`lan0`…, `wan`), the ports of its CPU (`eth0`…), bridges (`br-lan`), VLANs (`lan0.10`), Wi-Fi (`wlan0`) and virtual interfaces (`veth…`).
    `SwitchService::VIRTUAL_INTERFACES` leaves out the loopback, bridges, VLANs, Wi-Fi, tunnels and containers by name, and `eth0`, `eth1`… when
    the device names its ports `lan0`, `lan1`… (three or more: they are its CPU). The SNMP window lists **every Ethernet interface it has read** (the
    inventory `snmp` / `interfaces`) with a tick for the ports: tick any off or on against the default, no names to write (the settings `switch.hidden` and
    `switch.shown` keep only what differs from the default). The values of an interface that is switched off later stay as history, it is not shown. A
    device that tells no names keeps all its Ethernet interfaces. The agent reads the names as two more columns (15 of 16), no newer agent is needed.
  - **Which device is plugged into which port**, when the MAC table is there (below).
- The probe has `snmp` instead of `steps`: `{port: 161, snmp: {secret, columns[], version?}}`. The agent (`Invoke-SnmpWalk`) walks each column and
  returns numbers as `oid value` and strings as `oid x:hex` (255 bytes at most) in `body`. It reads nothing else: only the columns the server names
  (`SwitchService::SNMP_COLUMNS`), no SET, 16 columns, 2000 lines, 12 seconds, only the device it was given. The community is sealed for that agent like
  the password above (`secret` = `{"community": "..."}`) and goes with every probe (UDP has no login). It is stored encrypted, but shown in the
  SNMP window (it is read only, every signed-in user is a trusted operator).
- The server reads the lines (`SwitchService::parseSnmp`): down unless `ifOperStatus` is up; the link is the speed (`ifHighSpeed`, else `ifSpeed`): 1000 Mb/s and
  more *1000M full*, 100 Mb/s *100M full*, less *10M full* (the duplex is not read, full is assumed). Bad packets are `ifInErrors` + `ifOutErrors`.
- The same measured values, card, network map and alerts as for the TP-Link switch. An agent older than 1.39.0 gets no SNMP probe.
- **Devices are plugged into the ports by their MAC address** when the switch has a MAC table (BRIDGE-MIB `dot1dTpFdbPort`, Q-BRIDGE-MIB
  `dot1qTpFdbPort`, the bridge port turned into the ifIndex with `dot1dBasePortIfIndex`; the agent walks them as three more columns). `SwitchService::pair` matches the addresses with those of the devices (interfaces, the followed MAC of a ping-only device, the
  Wake-on-LAN MAC) and sets the switch and the port like by hand, marked `auto` (`lan.switch_auto`).
  - A device seen by several switches goes to the port with the fewest MAC addresses behind it (the nearest one); when two are equal, to none.
    A port with more than 16 addresses is a trunk to another switch: nothing is paired with it.
  - **By hand wins**: a device plugged in by hand (the dropdown of a port) is never moved. One paired by the MAC follows the cable (it moves when
    its address is learned on another port). One you take off its port is marked `off` and is not paired again.
  - The tables are kept in the cache for an hour; the devices are looked at again when a table changes, or every 10 minutes.
- Not covered (simple on purpose): SNMP v3, a port without `ifType` 6 (a LAG, a VLAN). A switch without a MAC table (TP-Link Easy Smart too) is plugged by hand.

#### Ports by hand (no agent version needed)

A ping-only device that nothing reads (no SNMP, no TP-Link page: an unmanaged switch, a modem, a patch panel) gets ports from the item **Ports** of its menu
(`Livewire\ManualPorts`): only how many (1 to 64; empty = none, setting `switch.ports`, `HasDeviceSettings::switch_ports`).
- `SwitchService::manualPorts` / `reads` / `enabled`: a device that is read (SNMP, TP-Link) keeps its own ports, the number set by hand is ignored (and the window says so).
- The ports are numbers with the link `SwitchService::MANUAL_LINK` (-1, "Not read"): on the map they have the plain colour, no speed, and their cable is a plain line
  (`is-manual`); no packets, no alert for a cable (nothing is read, `plugState` is null). Fewer ports than before take the devices on the missing ones off them.
- What is plugged into a port is chosen by hand, as for any port (click it on the map). The choice contains the devices of its network and **the routers
  (gateways) of the other networks of the same site** (`SwitchService::candidates`: a modem behind or beside the router has its address in another network, but its cable
  is in a port of this one).
- A **gateway that is a device of the portal** and is plugged into a port has the cable drawn from that port to its card (`plug` of the gateway node, `network-map.js`).
- A gateway plugged into a port of **another gateway** (a modem that is the WAN of the router: `Turris` into a port of `Archer`) hangs under it: the map drops its link from the public address and links it from the modem (`NetworkMap`, "the way out"), so the chain is Internet → address → modem → router → its LAN. Nothing is read for it, it is what you set.

