# Instructions for Claude

## Agent (`src/powershell/app.ps1`)

- **Every change of `src/powershell/app.ps1` raises `$AgentVersion`** (line ~137) in the same commit.
  The server reads that line (`AgentScript::version()`) and offers an update only to devices whose
  agent is older, so a change without a new version never reaches the devices.
  - New feature or new log source / inventory field: minor (`1.30.1` -> `1.31.0`).
  - Fix only: patch (`1.31.0` -> `1.31.1`).
  - Commit subject: `Agent X.Y.Z: <what changed>`.
- A server feature that needs a newer agent says so: `(agents X.Y.Z+)` in `docs/` and the comment of the
  code, and a `*_VERSION` constant in `app/Models/Device.php` when it gates a command (see
  `CHROME_UPDATE_VERSION`). Agents older than that must keep working (the server ignores what they do not send).
- A function the agent runs in a background job (`Start-AgentJob -Functions ...`) must be in that list,
  otherwise it does not exist in the job.
- `pwsh` is not available in the cloud session: say that the agent code was not run.

## Log sources and parsers

- The agent does not interpret logs. A source (`linux.auth`, `docker.logs`, `web.log`, `windows.*`) is
  declared in `SecurityParsers::LOG_SOURCES` (the field the agent filters by) and read in
  `Get-SecurityLogs`; the parsers and rules live in the `detection-policy` repo (`parsers/`, `rules/`,
  `tests/fixtures/`, `scripts/feed.py`, `manifest.json`).
- A new source needs the same name in `scripts/feed.py` (`LOG_SOURCES`) of `detection-policy`, and that
  repo's `python scripts/feed.py build` and `check` before it is pushed.
- The server takes the feed from `HeimGuard/detection-policy` (renamed from PanoptiPulse).

## Git

- Develop on the branch the session names, push there, open a pull request only when asked.
