# Remediation scripts

## Remediation scripts

**Scripts** in the main menu (system admins only) lists the scripts in a data table (search, sorting, **Add** in a modal). Each script has a detail page with its code, fingerprint and all runs. The scripts are PowerShell, in the style of Intune remediations:

- **Detection script** (required): exit 0 means compliant, exit 1 means the remediation should run.
- **Remediation script** (optional): runs after a detection that exited with 1, then the detection
  runs again. Without a remediation, exit 1 means failed.
- Each script targets **All**, **Windows** or **Linux** and has a timeout (up to 1 hour).
- **Remediate manually** (a switch in the script): runs and schedules only detect. The device gets
  the run without the remediation script (its manifest and fingerprint leave it out, so no agent
  can remediate with it). Exit 1 is **Needs remediation**: the device shows an alert with
  **Remediate**, and its latest run has a **Remediate** button in the runs table (output column).
  Remediate starts a full run on that device (detection, remediation, detection again). The script
  page counts the devices that need it.

Before saving, both scripts are checked: up to 200 kB each, valid PowerShell (with the PowerShell
parser when `pwsh` is on the server, `MDM_PWSH` or `PATH`; otherwise unterminated strings,
here-strings and comments and unbalanced brackets are found), and the detection has an `exit 0` and
an `exit 1` outside comments and strings.

**AI** in the form replaces the fields with one text: describe the remediation you need (or, with code
in the form, what to change) and **Generate** writes the name, description, platform, timeout, both
scripts and Remediate manually into the form, to be checked and saved as usual. Only the text and the
script in the form are sent, nothing about the devices. Without the AI set up the button is off:

| Variable | |
| --- | --- |
| `MDM_AI_PROVIDER` | `anthropic` (Claude, default) or `openai` |
| `MDM_AI_API_KEY` | the API key of the provider |
| `MDM_AI_MODEL` | default `claude-opus-5-5` / `gpt-5` |
| `MDM_AI_URL` | another endpoint; with `openai` any server with the Chat Completions API and structured outputs (Ollama, LM Studio, vLLM, OpenRouter), e.g. `http://ollama:11434/v1/chat/completions`, where the key is optional |

The code is entered as text and stored byte for byte. Its **fingerprint** (SHA-256 over the
platform, the timeout and the hashes of both scripts) changes with every code change, and a new
version is created. **Run** opens a list of the devices with checkboxes. Devices on another
platform, agents that do not sign and devices with scripts disabled cannot be selected. Opened
again, the list has the devices of the last run selected. Offline devices run the script when they
come back within 24 hours. The results are shown under the script and in the **Scripts** tab of
the device: status, exit codes and up to 16 kB of output.

**Schedule** on the script page runs it again and again, like an Intune remediation: a cron expression
(`minute hour day month weekday`, e.g. `0 3 * * *` every day at 3:00, presets in the picker, the next
runs are shown) in the time zone of the server (`APP_TIMEZONE`, UTC by default), and the devices:
all of them (also the ones enrolled later), the ones with any of the chosen [tags](devices.md#tags), and picked
ones. Tags are resolved at every run. The scheduler (`schedule:run`, in the Docker image
`schedule:work`) starts each due minute once; a waiting run of the same script on an offline
device is replaced by the new one. Changing the schedule is not a new version of the script.

Security:

- **Only a trigger:** `runScripts` carries no data. The agent takes its runs over the signed API.
- **Signed manifest per run:** every run has a manifest signed with the server key, for this
  device and this run, valid for 24 hours. The agent checks the signature, the device, the expiry,
  that the run id was not executed before, the platform and the SHA-256 of both scripts, and only
  then runs anything. A rejected run is reported back with the reason.
- **Memory only:** the code is never written to disk and never on a command line. A separate
  low-priority process reads it from stdin and checks its hash again. Scripts run one at a time,
  are killed at the timeout, and run as `SYSTEM` / root.
- **No network access:**
  - On Linux the script runs in an empty network namespace (`unshare --net`, also for every
    process it starts). Without `unshare` it does not run.
  - On Windows it runs from a copy of `powershell.exe` (`%ProgramData%\Laravel-MDM\sandbox`)
    that Windows Firewall rules block in both directions. It does not run when the rules are
    missing or changed, or the firewall is off for a profile.
  - This is defense in depth, not a hard boundary: a script running as `SYSTEM` / root that sets
    out to reach the network can get around it. For example, on Linux it can enter the network
    namespace of PID 1; on Windows it can start another program from System32. DNS lookups on
    Windows go through the DNS Client service. The signature is what keeps foreign code out.
    The isolation keeps scripts from downloading or sending anything.
- **Local switch:** `-DisableScripts` (`-EnableScripts` to allow them again, or `scripts_enabled`
  in `config.json`) turns scripts off on the device. The server cannot change it. Likewise
  `-NetworkDiscovery off|neighbours|scan` ([Network discovery](networks.md#network-discovery)).
- **Audit:** creating, changing, removing and running a script (with its fingerprint and devices)
  is written to the audit log.
