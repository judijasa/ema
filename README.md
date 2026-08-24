# ema

Package manager for MariaDB.

## Connectivity

`ema` reads its connection file by machine mode, and resolves a database to
its `[<dbname>]` section (the section header is the database name):
- `EMA_TARGET=local` (default) reads `var/reuter.local.ini` — the dev
  sandbox, generated and updated by `ema init db <name>`.
- any other `EMA_TARGET` (e.g. `prod`) reads `REUTER_INI` (fallback
  `etc/reuter.ini`, a committed example of the prod shape).

Connections use the selected section's `MYSQL_UNIX_PORT` (local socket) when
the section defines it, otherwise `SERVER`/`PORT` (TCP). The section is
authoritative: an exported `MYSQL_UNIX_PORT` in the shell does not override a
section that lacks the key. On the prod DB host, each `[<dbname>]` section
carries `MYSQL_UNIX_PORT` so `ema init db <name>` (run as root over the
socket) works, while the app layer keeps reading `.env` and stays on TCP.
`DBUSER` (env) is the client user for non-local targets.

The prod `[<dbname>]` sections are supplied by the host's deployment tooling;
`ema` needs no extra config to reach them (the transport, e.g. ZeroTier, is
just whatever `SERVER` resolves to).
