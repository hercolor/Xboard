# PRD — Local development runtime scripts

## Background

Xboard can be made runnable locally without Docker by installing Composer/Node dependencies, enabling missing PHP DB extensions from downloaded packages, running a local Redis, initializing SQLite, and launching `artisan serve`. The manual steps are too fragile for repeated API/UI development.

## Goal

Provide small, reviewable scripts that make the local runtime repeatable:

- `scripts/dev-up.sh`: prepare local runtime dependencies, ensure `.env`, run install when needed, start Redis and Laravel dev server.
- `scripts/dev-status.sh`: report Redis, Laravel server, key environment values, and smoke endpoints.
- `scripts/dev-down.sh`: stop local Redis and Laravel processes started by the scripts.

## Non-goals

- No production deployment changes.
- No Docker compose replacement.
- No API/auth/store/business logic changes.
- No migration away from official Docker image.
- No dependency version changes in Composer or package manifests.

## Requirements

1. Scripts must be safe to run repeatedly.
2. Scripts must not require sudo.
3. Scripts must keep generated runtime artifacts under `.local/` and existing gitignored runtime locations.
4. Scripts must support the current proven SQLite install path.
5. Scripts must expose admin path and credentials guidance after startup without hardcoding secrets beyond the current local install flow.
6. Verification must include shell syntax checks and real HTTP smoke checks.

## Acceptance criteria

- `bash -n scripts/dev-up.sh scripts/dev-down.sh scripts/dev-status.sh` passes.
- `scripts/dev-up.sh` can start the app or report it already running.
- `scripts/dev-status.sh` shows app status and successful smoke checks.
- `scripts/dev-down.sh` stops script-owned processes.
- Re-running `scripts/dev-up.sh` after `dev-down` restores a working `http://127.0.0.1:8001`.
- `git status` contains only intentional script/docs changes.
