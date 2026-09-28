# Implicit remote transport for the name-addressed mariadb verb — Plan & Progress

Date: 2026-09-27
Repos: ema (this repo).

## Decision

`ema mariadb <db>` is host-bound by construction: the section's `MYSQL_UNIX_PORT`
selects the instance's **local** socket, and root/unix_socket auth exists only on
the instance's own host. Run anywhere else, the verb dies with a socket error
(ERROR 2002) — the socket path is present, the socket is not.

The host is already in the config: the section's `SERVER` is the instance's
address. What `SERVER` cannot say is whether *this* machine is that host — and
that is a **presence** question, not an identity one. An instance exists on
exactly one host, at `$EMA_PROD_BASE/<db>/`; no other machine holds it. So the
verb can answer it by looking:

    $EMA_PROD_BASE/<db>/ exists here  -> the section's own endpoint (today's
                                         behaviour: its socket as root, or its
                                         SERVER/PORT when it defines none)
    absent                            -> the section's SERVER/PORT over TCP as
                                         DBUSER

No new flag, no IP comparison, no new config key. `status` already computes this
same fact (it prints the prod instance path "when it exists on this host, `-`
otherwise"), so the primitive is already in the script.

The local path is unchanged, so running on the DB host — today's documented
procedure — behaves exactly as before.

**No SSH hop.** An earlier draft of this plan escalated off-host to
`ssh root@$SERVER` against the instance's socket. Rejected: it introduces a
second, inferred root path into the DB host for a verb that otherwise never
reaches a host it does not run on, and the instance's root account is a
socket-auth identity (`unix_socket` maps the OS user) — meaningful on its own
host, arbitrary off it. The inspiration tool (schematic) carries no SSH in its
connection model at all: its basedir-addressed verbs are local-only, and
crossing hosts is a credentialled TCP URI (an explicit role plus password). ema
follows that shape — the section's `SERVER`/`PORT` is the off-host path,
`DBUSER` the explicit SQL user, and the credential stays with the client
(`DBPASS` → `MYSQL_PWD`, or `~/.my.cnf`), so nothing new is stored in the
endpoint-only connection file.

Scope: `mariadb` **only**. `create` and the sandbox build also call the shared
`_mariadb_args`; they must stay local-only — a host is not created from off-host,
and its instance dir does not exist yet, so presence-detection would invert them.
The branch therefore lives in `_cmd_mariadb` after `_load_target`, never in
`_mariadb_args`.

## Mechanism

Presence test, then one of two client invocations:

```
# present (unchanged)
<mariadb> -u <DBUSER|$USER> [--socket=$MYSQL_UNIX_PORT | -h $SERVER -P $PORT] "${args[@]}"

# absent
<mariadb> -u $DBUSER -h $SERVER -P $PORT "${args[@]}"
```

- `DBUSER` is required off-host and refused when unset: the operator's own
  account name is not a meaningful SQL user on a machine that is not the host.
- `DBPASS`, when set, is forwarded as the client's own `MYSQL_PWD` (environment,
  never argv). Unset, ema supplies nothing and the account decides: a
  passwordless account — or one already covered by `MYSQL_PWD`/`~/.my.cnf` —
  connects, and a refusal *for want of a password* (`ERROR 1045 … using password:
  NO`) is reported as it comes, with the client's exit code. ema adds no `-p` and
  makes no probing connection, so the client runs exactly once, with the caller's
  own args, streaming its own stderr.
- stdin passes through, so `-e "<sql>"` and `< file.sql` both work.
- The client's exit code propagates.
- The off-host branch prints a one-line note on stderr (`<db> is not on this
  host; connecting over tcp to $SERVER:$PORT as $DBUSER`) so an inferred
  transport is never silent.
- Off-host guards: a section without `SERVER`, or without `PORT`, is refused
  rather than guessed.

## Changes

### ema (this repo)

- [x] `ema` — `_cmd_mariadb`: after `_load_target`, branch on the prod instance
      dir (`$EMA_PROD_BASE/<db>/`): present → the existing `_mariadb_args` path;
      absent → the off-host TCP path. Sandbox mode never reaches the branch.
- [x] `ema` — new `_offhost_connect`: sets `_MARIADB_ARGS`/`_MARIADB_USER` from
      the section's TCP endpoint and `DBUSER`; guards missing
      `SERVER`/`PORT`/`DBUSER`; one-line note on stderr.
- [x] `ema` — new `_forward_dbpass`: `DBPASS` → the client's `MYSQL_PWD` on the
      DBUSER connections (prod, socket or TCP); the sandbox root path ignores it.
- [x] `ema` — `_mariadb_args` stays the local builder; `create` and the sandbox
      build keep calling it and are untouched.
- [x] `ema` — `_help_mariadb`, the header resolution paragraph and the
      `Environment` block: `DBUSER` required off-host, `DBPASS` documented.
- [x] `README.md` — `mariadb` row and the connectivity paragraph.
- [x] `doc/system/mariadb-remote.md` — new: the presence rule, the TCP-as-DBUSER
      transport, why not an SSH root hop, credentials, sections whose instance is
      elsewhere, and the off-host prerequisites.

## Open items

- **Inference, not a flag** — the transport is decided by instance presence. That
  rests on "an instance exists on exactly one host"; if that ever stops holding
  (e.g. a replicated copy of the instance dir on a second host), the test must be
  revisited.
- **`EMA_TARGET` keeps the file axis** — unchanged (`_reuter_file` →
  `_prod_reuter_file`, `$REUTER_INI` fallback `etc/reuter.ini`). The transport
  adds no second resolver. A caller that must be mode-deterministic pins
  `EMA_TARGET=prod` itself rather than relying on the ambient value.
- **No `--remote`** — rejected: `SERVER` already carries the destination, so the
  only thing a flag could add is "this machine is not the host", which the
  presence test answers without being told.
- **IP comparison** — rejected: `SERVER` is one of several addresses a host may
  know itself by, so a mismatch would silently pick the wrong transport — and the
  branch is untestable from off-host.
- **SSH hop** — rejected (see Decision); revisit only if off-host access must
  work with no TCP-capable account on the instance's host.
- **Sandbox symmetry** — none, by construction: `var/sandbox/<key>` is local.
- **`values --remote`?** **no** — `values` reads and prints a section; it needs no
  connection at all.
- **No prompt** — ema adds no `-p` and makes no probing connection, so the client
  is invoked exactly once. An interactive prompt was implemented as a two-step
  handshake (probe with no password; on a password-less refusal with a terminal
  present, retry with the client's bare `-p`) and then dropped: it made one
  invocation behave differently by tty, and the password belongs to `DBPASS`,
  `MYSQL_PWD` or `~/.my.cnf` like any other client credential.
- **Non-interactive off-host SQL** — piping (`< file.sql`) needs a credential
  source (`DBPASS`, `MYSQL_PWD`, `~/.my.cnf`) for an account that has a password.
