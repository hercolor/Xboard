# Sing-box rule-set mirror

Xboard mirrors the upstream CN Sing-box rule sets on the panel server so app clients do not download `raw.githubusercontent.com` directly.

## Public endpoints

After syncing, clients can download:

- `/rules/sing-box/geosite-cn.srs`
- `/rules/sing-box/geoip-cn.srs`

The Sing-box subscription template uses `$rules_base_url`, which is replaced with the current panel base URL during subscription generation.

## Manual sync after deploy

Run this once after deploying a new image or changing the Sing-box template:

```bash
php artisan sing-box:sync-rules --refresh-template
```

This command:

1. publishes `resources/rules/default.sing-box.json` into the database template named `singbox`;
2. downloads upstream `geosite-cn.srs` and `geoip-cn.srs`;
3. writes them atomically under `storage/app/rules/sing-box/`;
4. keeps the previous local files if a download fails or looks invalid.

## Scheduled updates

The scheduler runs this daily:

```bash
php artisan sing-box:sync-rules
```

It refreshes only the local `.srs` mirrors. It does not overwrite the database template unless `--refresh-template` is passed.

## Template safety

The default template keeps built-in basic CN/domain rules plus mirrored rule sets:

- built-in rules provide a fallback when rule-set download is unavailable;
- mirrored rule sets provide broader CN routing when available;
- `store_fakeip` is disabled;
- IPv6 TUN address is removed.
