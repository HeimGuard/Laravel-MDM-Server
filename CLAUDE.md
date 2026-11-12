# Instructions for Claude

Start with [`docs/development.md`](docs/development.md): where the code of each area is, how the updates work, how
to test and what has broken tests before. Read it instead of searching the code for the same.

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

## UI texts

- Every new text of the UI (`__('...')`, `@lang`, `{{ __(...) }}`) needs a translation in **every** file of
  `src/laravel/lang/*.json` (cs, de, es, fr, it): `LocaleTest::test_every_text_is_translated` fails otherwise.
  One line per language, same placeholders (`:name`), in the place where the neighbouring keys are.

## Log sources and parsers

- The agent does not interpret logs. A source (`linux.auth`, `docker.logs`, `web.log`, `windows.*`) is
  declared in `SecurityParsers::LOG_SOURCES` (the field the agent filters by) and read in
  `Get-SecurityLogs`; the parsers and rules live in the `detection-policy` repo (`parsers/`, `rules/`,
  `tests/fixtures/`, `scripts/feed.py`, `manifest.json`).
- A new source needs the same name in `scripts/feed.py` (`LOG_SOURCES`) of `detection-policy`, and that
  repo's `python scripts/feed.py build` and `check` before it is pushed.
- The server takes the feed from `HeimGuard/detection-policy` (renamed from PanoptiPulse).

## Data model (details and JSON examples: `docs/data-model.md`)

- **A refactoring of the data changes the backend only.** No change of templates (`resources/views`), JS, texts
  (`lang`), or of the API of the agent. Models keep returning the same shapes (`$device->services`, `->updates`,
  `->drives`, ...), built from the new tables. The agent code is not touched.
- Where new data goes (no new column, no new JSON blob in `devices` for any of these):
  - **A setting** (someone set it, rarely changes): `settings` through `HasSettings`, keys `group.name`.
  - **A measured number** (CPU, disk usage, ping; also an enum as a code): `HasValue`, `value_definitions` (model, `key`,
    `name`) + `value_samples`. A new kind of value is a new key, never a migration.
  - **A structured thing a model has** (service, container, process, port, user, posture, software, update):
    `HasInventory`, `inventory_definitions` (model, `key` = the kind, `name` = which one) + `inventory_samples`,
    the same shape as the values, JSON in `data`.
  - **Something that happened** (a log event, offline, an agent update, a finding of a port scan): `events`.
  - **Something a user did**: `activities` (`Audit`); its execution: `device_commands`, `script_runs`.
  - Alerts and notifications stay in `alert_events`. Raw logs are not stored, only the events made of them.
- History keeps **changes only** (a value that did not change adds no sample), for all of it one setting
  (`history_days`), no retention per key and no config file for it.
- **Never discard data.** Where two sources report the same thing (the auth log of the agent and the same messages forwarded
  as syslog), keep both: they are one event confirmed by the second source (`data.sources`, `verified`), not a filter that
  drops one of them and not two events. Data is only removed by the retention (`history_days`), never by a rule of the code.
- Owners are polymorphic (`*_type`, `*_id`): there is no foreign key, so deleting the owner must delete its rows
  (a `deleted` hook in the trait). Details that not every row has go to a `data` JSON, not to nullable columns.
- New domain logic goes to `app/Services` (`XService`, static methods like the SteelAnts packages), not to
  `app/Support`; a class of `Support` is moved when it is touched.
- A migration copies the data, has a `down()` that restores it, and is run with data on PostgreSQL both ways.
- **Test on SQLite and PostgreSQL** (`DB_CONNECTION=pgsql DB_DATABASE=...`). What only PostgreSQL rejects: `LIKE` is
  case-sensitive (use `whereLike`), `coalesce` of a number and a string, a number compared with `''`, a sequence that is
  not reset between tests (a test must not expect `id = 1`).

## Git

- Develop on the branch the session names, push there, open a pull request only when asked.
- No `Co-Authored-By:` or `Claude-Session:` line (or session link) in commit messages, pull request descriptions or any other message.
