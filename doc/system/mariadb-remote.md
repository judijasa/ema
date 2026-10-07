# Reaching a prod database from another host

How `ema mdb <db>` connects when the instance is not on the machine you run
it from. ema keeps its prod admin path on the instance's own host — root over
the instance socket — and reaches an instance that lives elsewhere over TCP as
an explicit SQL user. There is no SSH hop and no remote root path.

## Quick setup

On the machine that is *not* the instance's host (the `[<db>]` section must be
in the connection file, `REUTER_INI` / `etc/reuter.ini`):

```bash
export DBUSER=<sql user>       # required off-host
export DBPASS=<password>       # only if the account has one
ema mdb <db>
```

`DBPASS` is ema's knob: it is handed to the client as `MYSQL_PWD` (environment,
never argv — it never shows up in `ps`). A password the caller already exported
as `MYSQL_PWD`, or recorded in `~/.my.cnf`, works just as well; leave the
password out entirely for an account that has none.

The account that the remote side uses is consumer policy, like every other
service account — ema creates no users. On the instance's host:

```sql
CREATE USER '<sql user>'@'<client-ip>' IDENTIFIED BY '<password>';
GRANT SELECT, INSERT, UPDATE, DELETE ON <db>.* TO '<sql user>'@'<client-ip>';
```

## The rule

The transport is decided by instance **presence**, not by configuration:

| `$EMA_PROD_BASE/<db>/` on this host | Connection |
|---|---|
| present | the section's endpoint, as on the DB host today: its `MYSQL_UNIX_PORT` socket (`-u root`, unix_socket auth), or its `SERVER`/`PORT` when the section defines no socket; user `DBUSER`, else `$USER` |
| absent | the section's `SERVER`/`PORT` over TCP, `-u $DBUSER` (required off-host); the password comes from `DBPASS`, else from the client's own sources (see Credentials) |

An instance exists on exactly one host, at `$EMA_PROD_BASE/<db>/` — no other
machine holds it. So the verb can answer "am I the host?" by looking, and the
section's `SERVER` — which is the instance's address, but cannot say whether
*this* machine is that address — needs no comparison against local IPs. `ema
status` answers the same question the same way for its prod path column, and
`ema create` relies on the path when it provisions the instance.

The off-host branch prints a one-line note on stderr
(`<db> is not on this host; connecting over tcp to $SERVER:$PORT as $DBUSER`),
so an inferred transport is never silent. stdin passes through, so `-e "<sql>"`
and `< file.sql` both work; the client's exit code propagates.

## Why TCP as `DBUSER`, not an SSH hop

- **No privilege escalation.** An SSH hop as root would be a second, implicit
  root path into the DB host, decided by inference. ema has no other verb that
  reaches a host it does not run on, and the instance's root account is a
  socket-auth identity (`unix_socket` maps the OS user) — meaningful on the
  instance's own host, arbitrary anywhere else.
- **The section already carries the destination and the credential axis.**
  `SERVER`/`PORT` is the address the app layer uses; `DBUSER` is the existing
  "client user for non-targets" knob. The transport adds no key and no flag.
- **No secrets in ema.** A TCP connection needs no stored password: `DBPASS`
  lives in the caller's environment and reaches the client as `MYSQL_PWD`, a
  passwordless account connects as-is, and a password-protected one takes its
  password from `DBPASS` or from the client's own sources. Nothing new is written
  to the connection file, which stays endpoint-only.

## Credentials

`DBPASS`, when set, is forwarded to the client as `MYSQL_PWD` (environment, not
argv — it never shows up in `ps`). When it is unset, ema supplies nothing and the
account decides.

| the account | what happens |
|---|---|
| needs no password | connects silently — nothing is asked |
| already covered by the caller's `MYSQL_PWD` / `~/.my.cnf` | connects silently |
| needs one, and none is available | the server refuses (`ERROR 1045 … using password: NO`) and ema exits with the client's code |

ema never asks for a password: it adds no `-p` and makes no probing connection,
so the client runs exactly once, with the caller's own args, streaming its own
stderr. A password therefore has to arrive through `DBPASS`, `MYSQL_PWD` or
`~/.my.cnf` — non-interactive use (`ema mdb <db> < file.sql`, cron) included,
where a prompt would have nowhere to read from anyway. A password that *was*
supplied and rejected is reported as it comes.

`DBPASS` applies to the `DBUSER` connections — the prod paths, socket or TCP.
The sandbox root path authenticates by OS user (`unix_socket`) and ignores it.

## Sections whose instance is elsewhere

A section recorded with a TCP `SERVER`/`PORT` only — the primary's section on
the replica host, per `doc/system/replica-bootstrap.md` — is reached over TCP
from any host, including one where a *different* database happens to own that
name: the socket key it does not carry is the instance's socket, not yours. A
section that carries `MYSQL_UNIX_PORT` but whose instance is absent is still
reached over TCP, using the same `SERVER`/`PORT` keys.

## Prerequisites

- the `mariadb` client on `PATH` (the local environment provisions it, e.g. the
  dev shell's Nix config; the transport assumes it and cannot install it);
- `SERVER`, and `PORT`, recorded in the section — off-host, a section missing
  either is refused rather than guessed;
- a TCP-capable account for `DBUSER` on the instance's host, with grants on the
  database (see Quick setup);
- `DBPASS` (or `MYSQL_PWD` / `~/.my.cnf`) when that account has a password — ema
  never prompts for one;
- on the DB host, run the verb as root, as before — the presence test reads
  `$EMA_PROD_BASE/<db>/`, which is not world-readable.
