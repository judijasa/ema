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

Connections use the selected section's `MYSQL_UNIX_PORT` (local socket) when
the section defines it, otherwise `SERVER`/`PORT` (TCP). The section is
authoritative: an exported `MYSQL_UNIX_PORT` in the shell does not override a
section that lacks the key. On the prod DB host, `gen-reuter` writes
`MYSQL_UNIX_PORT` into each `[<dbname>]` section so `ema init db <name>` (run
as root over the socket) works, while the app layer keeps reading `.env` and
stays on TCP. `DBUSER` (env or `[dev]` in `etc/machines.ini`) is the client
user for non-local targets.

In php_daas_framework consumer repos, the `[<dbname>]` sections are generated
by the framework's `gen-reuter` from `etc/machines.ini` + `etc/deploy.conf` —
`SERVER` becomes the hosting server's address and `DBNAME` the mapped
database name — so `ema` needs no extra config to reach the prod databases
(the transport, e.g. ZeroTier, is just whatever that address resolves to).
