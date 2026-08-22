# ema

Package manager for MariaDB.

## Connectivity

`ema` reads `etc/reuter.ini` from the repo root. Sections are keyed by
database name:
- `EMA_TARGET=local` (default) uses `[local]` (dev sandbox socket), or a
  per-database `[local:<dbname>]` section when present.
- any other `EMA_TARGET` (e.g. `prod`) resolves `[<dbname>]` by the database
  name passed to the command — `ema mariadb <db>`, `ema init db <db>`, or
  `EMA_TARGET=<db> ema init tables <root-pkg>`.

Connections use `SERVER`/`PORT` from the selected section (or the
`MYSQL_UNIX_PORT` socket) and `DBUSER` (env or `[dev]` in
`etc/machines.ini`) as the client user for non-local targets.

In php_daas_framework consumer repos, the `[<dbname>]` sections are generated
by the framework's `gen-reuter` from `etc/machines.ini` + `etc/deploy.conf` —
`SERVER` becomes the hosting server's address and `DBNAME` the mapped
database name — so `ema` needs no extra config to reach the prod databases
(the transport, e.g. ZeroTier, is just whatever that address resolves to).
