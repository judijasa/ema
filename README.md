# ema

Package manager for MariaDB.

## Connectivity

`ema` reads `etc/reuter.ini` from the repo root; `EMA_TARGET` selects the
section (default `local`). For non-local targets (`EMA_TARGET=prod`) it
connects over TCP using `SERVER`/`PORT` from that section and `DBUSER`
(env or `[dev]` in `etc/machines.ini`) as the client user.

In php_daas_framework consumer repos, the `[prod]` section of `etc/reuter.ini`
is refreshed by the framework's `gen-reuter` from `etc/machines.ini` +
`etc/deploy.conf` — `SERVER` becomes the DB host's ZeroTier IP and `DBNAME`
the mapped database name — so `ema` needs no extra config to reach the prod
database over ZeroTier.
