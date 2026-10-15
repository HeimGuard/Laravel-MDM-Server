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
- The detail has a monitor in the style of [Uptime Kuma](https://github.com/louislam/uptime-kuma):
  the last 50 pings as a bar (green answered, red not), the response now and on average, the
  uptime of the last 24 hours and 30 days, and the response time over 1 h, 24 h, 7 d or 30 d with
  the unanswered periods marked. Every ping is kept for 30 days (`ping_results`, pruned daily).
- In the device list it has a network icon; alerts offer the status only (offline for *n* minutes). Its address, prefix and MAC are changed in
  **Ping settings** in the device menu.
