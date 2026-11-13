# Alerts and notifications

**Smart alerts** show what needs attention on a device (restart required, a newer agent, updates,
low disk space, disk health, failed services and remediations, security findings, a failed wake, a MAC or IP address
that changed, failed commands, errors from the agent's log) with the action that fixes it. An alert can be dismissed: it stays hidden until it says something else (e.g. more
updates) or goes away and comes back. The **Smart alerts** dashboard widget lists them for all
devices, with one button per alert for all devices that can take the action now.
A device without a heartbeat for 90 seconds is shown as offline.

**Agent errors:** agents 1.13.2+ send the errors they write to their log (a failed inventory,
report or command status, a crash of the agent) to the server once it answers, each message once
with how often and when it happened; they are kept over a restart until the server took them. Not
reaching the server (network down, the WebSocket reconnecting) is not one. The device shows them as
an alert (the newest ones of the last 7 days) until **Clear**.


## Notifications and alerts

Notifications link to the device. The scheduler that sends them has no request to take the address
from, so it uses `APP_URL` when that is set to a real address, otherwise the address system admins
open the portal with (remembered in `storage/app/portal-url`; not localhost, and not any request's
`Host` header).

**Notifications** in the main menu, per user and in the style of
[Beszel](https://beszel.dev/guide/notifications/):

- **Where to send:** e-mail addresses (the server's `MAIL_*` settings) and push / webhook URLs in
  the [Shoutrrr](https://containrrr.dev/shoutrrr/) format, each with a **Test** button:

  | Service | URL |
  |---|---|
  | ntfy | `ntfy://ntfy.sh/topic`, `ntfy://user:password@ntfy.example.com/topic`, `ntfy://:token@host/topic` (`?priority=high&tags=warning`) |
  | Discord | `discord://token@webhookid` (from `https://discord.com/api/webhooks/webhookid/token`) |
  | Telegram | `telegram://bottoken@telegram?chats=@channel,123456789` |
  | Gotify | `gotify://gotify.example.com/AppToken` |
  | Slack | `slack://hook:T000-B000-XXXX@webhook` |
  | Pushover | `pushover://shoutrrr:apiToken@userKey` |
  | Webhook | `generic://example.com/hook` (JSON POST `{"title", "message"}`), `generic+http://` without TLS |

  `?disabletls=yes` sends over plain http (self-hosted ntfy, Gotify or webhooks in your network).

- **Alerts:** a rule is a condition on devices (all, [tags](devices.md#tags) or picked ones) and picks where it
  is sent (all channels by default, or only some of them; the rules are per user):

  | Alert | When |
  |---|---|
  | Status | the device is offline for at least *n* minutes |
  | CPU usage | the average over the last *n* minutes is above the threshold (%) |
  | Memory usage | the average over the last *n* minutes is above the threshold (%), or the free memory is below a size (GB) |
  | Disk usage | a drive is fuller than the threshold (%), or has less free space than a size (GB) |
  | Disk health | a disk reports a S.M.A.R.T. warning or failure |
  | Services | a service failed, or a container is unhealthy, dead or restarting |
  | Remediations | the latest run of a remediation script failed |
  | Security findings | the [security scanner](security-scanner.md#security-scanner) found something of high or critical severity that nobody acknowledged |
  | New device | a device is enrolled with the agent or added as ping-only (once each, nothing to resolve; sent with its name, system and agent version after its first report, at the latest 10 minutes after enrolment) |
  | Unknown device | an agent sees a device the portal does not know in its network (once each, see [Network discovery](networks.md#network-discovery)) |
  | Exposed service | a port scan of the [network discovery](networks.md#network-discovery) found an exposed or weak service of medium severity or worse on a host that is not a managed device (once per finding, found since the alert was created, at most a day back; the message names the service, the host, its network and the agent that scanned, and links to the host on the network map; nothing to resolve; the finding itself is listed under **Security**) |
  | Address changed | a ping-only device with a MAC address is seen at another IP address (DHCP): the portal follows it, the alert says to reserve the address for the MAC in the router (static lease) or set it on the device |

  Disk and memory switch between **%** and **GB**: a percentage suits drives of the same size, a
  size suits the big ones (10 % of 4 TB are still 400 GB) and memory of different machines. GB are
  1024 based, as shown in the portal.

  The bell on a device switches the alerts for just that device, with a slider for the threshold
  and the minutes (like the bell of a system in Beszel).

The scheduler checks the rules every minute. When a rule starts to hold on a device, the user gets
one notification (🔴) and the alert is shown under **Firing now** on the **Alerts** tab (the **History** tab keeps the last 100); when it stops holding, a second
one (✅, with how long it lasted). Nothing is sent again in between. An offline device keeps its CPU
and memory alerts as they are until it reports again.
