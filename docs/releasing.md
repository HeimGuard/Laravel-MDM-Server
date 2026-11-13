# Releasing

## What a version is

The version of HeimGuard is the version of the agent: `$AgentVersion` in `src/powershell/app.ps1` (line ~137).
The tag is `v` and that number, `v1.33.1`, and the Docker image of a tag carries the same number (`1.33.1`).
The server offers an agent update to a device whose agent is older than `$AgentVersion` of the script in its image
(`AgentScript::version()`), so the agent version moves with every change of `app.ps1` (see `CLAUDE.md`): new
feature, minor; fix only, patch.

Changes of the server alone do not change the agent number: they go to `Unreleased` of the changelog and reach the
servers with the image of `master` (`latest`). A number is only needed when you want to tag a release.

## The changelog

`src/laravel/CHANGELOG.md` is the changelog ([Keep a Changelog](https://keepachangelog.com/en/1.1.0/): Added /
Changed / Fixed / Security). It is shown in the portal under `/changelog` (`ChangelogController` reads it with
`base_path('CHANGELOG.md')`, and the image copies it with the rest of `src/laravel`).

**Every user-visible change adds a line to `CHANGELOG.md` under Unreleased**, in the same pull request. Write it for
the admin (what changed for them, with the `#NNN` of the pull request), not for the developer. A change nobody sees
(a refactoring, a test, a comment) needs no line.

## Steps of a release

Tagging is optional. Every merge into `master` already publishes the image `master` / `latest`; a tag only adds the
fixed `X.Y.Z` and the moving `X.Y` image tags of the same code. Nothing needs a tag, so tag when you want a number to
point at (for an installation that pins a version, or to say what a changelog heading is).

1. On master, check that the agent version is the number to release (`grep -n 'AgentVersion =' src/powershell/app.ps1`)
   and that the `tests` check of master is green.
2. In `src/laravel/CHANGELOG.md` rename the heading `## [Unreleased]` to `## [X.Y.Z] - YYYY-MM-DD`, and put a new empty
   `## [Unreleased]` above it. Commit it (`Release X.Y.Z`) and merge it into master.
3. Tag that commit and push the tag:

   ```bash
   git tag -a vX.Y.Z <commit> -m "HeimGuard X.Y.Z"
   git push origin vX.Y.Z
   ```

4. Read the run of the tag in GitHub Actions (below). When it is green the image `X.Y.Z` is in the registry.

## What the workflow does

`.github/workflows/docker.yml` ("Docker image") runs on a push to `master` or `main`, on a push of a tag `v*`, on every
pull request and by hand. Two jobs:

- **`tests`**: PHP 8.4, `composer install`, `npm ci`, `npm run build`, `php artisan test` (SQLite).
- **`build`** (only when `tests` passed): builds the image (`src/docker/Dockerfile`, for `linux/amd64` and `linux/arm64`).
  On a pull request it only builds, nothing is pushed. Otherwise it pushes to `ghcr.io/heimguard/laravel-mdm-server`:

  | Event | Image tags |
  |-------|-----------|
  | push to `master` | `master` and `latest` |
  | tag `v1.33.1` | `1.33.1` and `1.33` (the `v` is dropped; no `latest`) |
  | pull request | `pr-<number>`, not pushed |

So `latest` and `master` follow every merge into master, and a tag adds the fixed `X.Y.Z` and the moving `X.Y` of the
same code. Nothing else happens on a tag: no GitHub release is created and nothing is deployed. A server updates
with `docker compose pull && docker compose up -d`; its agents then see the newer `$AgentVersion` and offer the
update. Runs of the same ref cancel each other (`concurrency`), so a push to master while the tag of an older commit
builds does not disturb it.

If a tag was pushed by mistake: delete it (`git push origin :refs/tags/vX.Y.Z`, `git tag -d vX.Y.Z`) and the image tags
`X.Y.Z` and `X.Y` in the package page of the registry.
