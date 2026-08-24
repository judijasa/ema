# ema

Package manager for MariaDB.

## Connectivity and machine mode

`ema` reads its connection file by machine mode, and resolves a database to
its `[<dbname>]` section (the section header is the database name):
- `EMA_MODE=dev` (default) reads `var/reuter.local.ini` — the dev
  sandbox, generated and updated by `ema init db <name>`.
- any other `EMA_MODE` (e.g. `prod`) reads `REUTER_INI` (fallback
  `etc/reuter.ini`, a committed example of the prod shape).

`EMA_MODE` is also the mode switch for `ema init db` / `ema init tables`,
not just a file selector. `dev` mode creates/updates `var/reuter.local.ini`
and refuses root. Any other value (`prod`) never writes a connection file:
the `[<dbname>]` section must already exist (on prod, gen-reuter writes it
at deploy) and a missing section is a loud error. Prod machines run prod
mode by default only because the deployed `.env` carries `EMA_MODE=prod`;
ema never reads `.env` itself, so the running shell needs it in scope
(`set -a; . .env`) — without it the `dev` fallback misfires on a prod
server.

Connections use the selected section's `MYSQL_UNIX_PORT` (local socket) when
the section defines it, otherwise `SERVER`/`PORT` (TCP). The section is
authoritative: an exported `MYSQL_UNIX_PORT` in the shell does not override a
section that lacks the key. On the prod DB host, each `[<dbname>]` section
carries `MYSQL_UNIX_PORT` so `ema init db <name>` (run as root over the
socket) works, while the app layer keeps reading `.env` and stays on TCP.
`DBUSER` (env) is the client user for prod targets.

The prod `[<dbname>]` sections are supplied by the host's deployment tooling;
`ema` needs no extra config to reach them (the transport, e.g. ZeroTier, is
just whatever `SERVER` resolves to).
