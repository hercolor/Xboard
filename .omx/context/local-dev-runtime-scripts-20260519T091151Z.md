# Context Snapshot — Xboard local dev runtime scripts

- **Timestamp (UTC):** 2026-05-19T09:11:51Z
- **Task statement:** Continue with Ralph after manually making Xboard runnable; solidify the local runtime setup into reusable development scripts.
- **Desired outcome:** Developers can start, check, and stop the local Xboard runtime with scripts instead of repeating manual PHP extension extraction, Redis boot, install checks, and `artisan serve` commands.

## Known facts / evidence

1. Current repository is Laravel + admin dist assets; root `.env` is gitignored.
2. Local machine PHP 8.3 has core `PDO` but lacks `pdo_sqlite`, `sqlite3`, and `pdo_mysql` in the system extension directory.
3. We successfully downloaded matching Ubuntu PHP 8.3 `.deb` packages and enabled DB extensions through a project-local wrapper under `.local/`.
4. System Docker socket is not usable by the current user (`permission denied`), so scripts must support a non-Docker path.
5. Local Redis was successfully run from project-local downloaded packages under `.local/redis-root` with `LD_LIBRARY_PATH` for extracted runtime libraries.
6. Installed app state exists in `.env` and `.docker/.data/database.sqlite`; `/` redirects to `/{secure_path}`, admin shell returns `200`, and `GET /api/v1/guest/comm/config` returns `200`.
7. `.local/` is currently excluded locally via `.git/info/exclude`, but repo-level scripts should not require committing `.local/` artifacts.

## Constraints

- Do not change business logic, routes, API contracts, auth behavior, or stores.
- Do not require sudo or Docker permissions.
- Keep scripts idempotent and reversible.
- Avoid committing generated dependencies or local runtime artifacts.
- Use the existing installed SQLite/Redis local dev path by default.

## Unknowns / open questions

- Whether future machines have `apt` access; scripts should fail clearly if package download is unavailable.
- Whether users want MySQL-backed local dev later; current goal is the already-proven SQLite path.
- Whether port `8001`/`6379` is always available; scripts should detect conflicts where practical.

## Likely codebase touchpoints

- `scripts/dev-up.sh`
- `scripts/dev-down.sh`
- `scripts/dev-status.sh`
- `docs/admin-only-auth-task-list.md` or new local run note if needed
- `.gitignore` if project-level exclusion for `.local/` is useful

## Risk tradeoffs

- Keeping `.local/` as generated runtime cache avoids system mutation but requires scripts to download packages on first run.
- Using SQLite avoids needing MySQL credentials and matches the successful local smoke test.
