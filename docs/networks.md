# Networks and discovery

## Networks

**Networks** in the main menu draws the fleet as a map, built from what the agents report. Top down:

1. **Internet**.
2. **Public addresses** (sites): of each network, the most common address its agents see the
   internet from, else reach the server from. Agents 1.21.0+ ask for it once an hour for each
   connected interface with a gateway (a request bound to that interface, to
   `MDM_PUBLIC_ADDRESS_URL`, `https://ifconfig.me/ip` by default), so each connection of a device
   is behind its own router's address: a laptop on a VPN to the server only (the server sees the
   tunnel's address) or with LAN and a mobile network at once. An agent that reaches the server
   over a private address is inside the network of this server (split DNS), so one device of a
   LAN going over the router's public address (hairpin NAT) and another from inside are still one
   site; one that reaches it over a VPN of its own (a private address none of its other interfaces
   has) is not, and without a reported address its networks are at **Unknown address**. A public
   address devices were seen behind stays on the map when nobody is there anymore, faint, with
   when it was seen last. `MDM_DETECT_PUBLIC_ADDRESS=false` stops the agents asking too.
3. **Gateways**: the default gateway of the interfaces (agents 1.15.0+, with its MAC from the
   neighbour table). One router of several networks (a LAN and a guest Wi-Fi) is one node.
4. **Networks**: the IPv4 subnets of the interfaces, LAN, Wi-Fi, LAN + Wi-Fi or mobile. A network
   is told apart by the MAC of its gateway (every device in it sees the same router), so one LAN is
   one network whatever address its devices reach the server from, and two homes with
   192.168.1.0/24 stay two. A device whose ARP table lacks the router joins the one network of its
   subnet whose devices reach the server from the same addresses; without a gateway MAC at all
   the public address decides.
   VPNs (WireGuard, Tailscale, …) span the sites and hang from the internet; the /32 of a VPN
   client is in its /24. Docker, VM and Bluetooth interfaces are left out.
5. **Devices** under their network: a click on a network shows or hides its devices (up to six
   are shown at first, the rest on a click). They sit in a grid under it, without a frame, the
   network centered over them; its unknown devices are one card. All links are right-angled with
   rounded corners: solid for LAN, dotted for Wi-Fi, dashed for VPN. A device of several
   networks is in the grid of its main one (LAN, then Wi-Fi, then VPN), in the first column,
   with a tag for each other network; its links to those are drawn while you point at the
   device or the network (not all devices of a LAN are in the VPN). Ping-only devices are in the
   network of their address; their agent shows how many it pings.

**This server** is placed in the network the agents reach it from over private addresses. Its
public address is `MDM_PUBLIC_ADDRESS` when set, else the address another device of that network
reaches it through (the router's), else what its name (`APP_URL`, else the address system admins
open the portal with) resolves to, else the address the server reaches the internet from, which
it asks of `https://ifconfig.me/ip` (`MDM_PUBLIC_ADDRESS_URL` for another service) once an hour:
only the request leaves the server, nothing about the fleet. `MDM_DETECT_PUBLIC_ADDRESS=false`
turns it off for a server without internet access.

A link that is down (a disconnected interface, an offline device) stays as a faint line; a network
without anything online is drawn faint. A device that is the only agent in its networks is
**isolated** (dashed orange): nothing next to it can wake it or ping the devices around it. The map is
dragged and zoomed (wheel, two fingers, the buttons; **fit** shows all of it), a click on a device
opens it, and it follows the agents live over Reverb. It fills the window (no page scroll); the
**Networks** switch next to **Diagram** lists every network instead, with its devices, gateway, the
agents that can ping and wake in it, its unknown devices and **Scan** in the top right corner.

### Network discovery

Agents 1.16.0+ also report the devices they see in their networks, so the ones the portal does not
know show up. What an agent may do is set on the device, in `network_discovery` in its
`config.json` (`-NetworkDiscovery` at install); the server cannot change it:

| `network_discovery` | The agent |
|---|---|
| `off` | reports nothing and refuses scans |
| `neighbours` (default) | sends its ARP table with every report (passive, nothing is sent into the network) |
| `scan` | also scans a network of its interfaces when asked in the portal |

- **Unknown devices:** a neighbour in the network of one of the agent's interfaces (Docker, VMs and
  VPNs left out) whose MAC address no device of the portal has (their interfaces, gateways,
  ping-only and Wake-on-LAN settings). It is kept per MAC address and network (its gateway), so a device
  with a dynamic address is still one entry. It is drawn dashed under its network (at most 8,
  the rest as **+n unknown**) and listed in the network's card with its address, name and MAC
  (**random MAC**: a private address a phone or laptop made up for that Wi-Fi). Neighbours seen in
  the last 24 hours are shown; ones not seen for 30 days are forgotten (ignored ones after 180).
- **Add** makes it a ping-only device with its address, prefix and MAC. **Ignore** hides it (and
  its alerts); ignored ones are listed under the networks and can be shown again.
- **Dynamic addresses:** when an agent sees the MAC of a ping-only device at another address of
  the same network (DHCP, a readdressed device), the portal does not move it: the device gets a
  smart alert **The device has another address** with **Use 192.168.1.9** (asks first), and the
  *address changed* alert rules are told once per new address: for an address the report has that
  MAC at alone, or any address of it while the device does not answer where it is pinged. Agents
  1.21.0+ mark ARP entries confirmed lately; an old entry the table still keeps after a change
  (Windows keeps them for hours) does not replace an address confirmed in the last 30 minutes.
- **Scan** (in the network's card, or **Scan 192.168.1.0/24** in the menu of an agent for each of
  its networks, up to a /22): an online agent in the network with
  `network_discovery` `scan` pings every address of it (128 at once, a few seconds for a /24),
  which also fills its ARP table with every device that answers ARP, looks up the names of the
  ones that answered (reverse DNS) and reports at once. Progress and result are shown in the card
  and in the agent's commands. The agent scans only networks of its own interfaces, one at a time.
- **Alert:** the **Unknown device** rule notifies once about every unknown device that appears.
- **Portal switch:** system admins turn **Network discovery** off on the Networks page; the server
  then takes no neighbours and sends no scans, whatever the agents allow.

The level of each agent is shown in its **Agent** tab. To allow scans on many devices, every
installation comes with the remediation script **Allow network scans** (all platforms, manual
remediation): run it on the devices that should scan, it detects which ones do not allow scans yet,
and **Remediate** sets `network_discovery` to `scan` in their `config.json` (a backup is kept as
`config.json.bak`). The agent reads it with its next report. Scripts find the agent's directory in
`MDM_AGENT_DIR` (also with `-InstallPath`).
