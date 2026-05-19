# Test Spec — Local development runtime scripts

## Static checks

- `bash -n scripts/dev-up.sh scripts/dev-down.sh scripts/dev-status.sh`
- Review scripts for destructive commands and ensure cleanup is scoped to `.local/run` process IDs.

## Runtime checks

1. Run `scripts/dev-status.sh` while the current manual server is running.
2. Run `scripts/dev-down.sh` and confirm Redis/Laravel local processes stop.
3. Run `scripts/dev-up.sh` and confirm:
   - PHP wrapper exposes `pdo_sqlite`, `sqlite3`, `pdo_mysql`.
   - Redis responds to ping.
   - Laravel server responds on `127.0.0.1:8001`.
   - `/` returns `302` to secure admin path.
   - `/{secure_path}` returns `200`.
   - `/api/v1/guest/comm/config` returns `200`.
4. Run `scripts/dev-status.sh` again and confirm green summary.

## Completion evidence

- Command outputs from syntax checks and smoke checks.
- Architect/static review approval for script boundaries.
