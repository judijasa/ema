# Host-level ssl-ca / ssl-crl (the server half of `REQUIRE X509`)

How a prod instance gets a CA to verify **client** certificates against, so a
consumer's `REQUIRE X509` service account can authenticate at all: `REQUIRE
X509` without a CA authenticates nobody, because the server has no anchor to
check a client certificate against. The CA is a host-level fact — one CA,
shared by every database the host serves — so ema reads it once per run from a
consumer-owned host config and writes it into each instance's `[mysqld]` block.
The optional **`ssl-crl`** is the revocation half: a Certificate Revocation
List the server consults when verifying a client cert, so a leaked certificate
can be revoked without rotating the CA.

## Quick setup

On the DB host, at the repo root. The CA and CRL files themselves are installed
out of band by the operator — ema never copies certificate bytes:

```bash
install -m 644 /path/to/ca.pem  /etc/ssl/ema-ca.pem
install -m 644 /path/to/crl.pem /etc/ssl/ema-crl.pem
```

The committed `etc/ema.default.conf` ships the `ssl-ca` fallback (and a
commented `ssl-crl`). Set the value there — or, for a host that diverges from
it, in the git-ignored `etc/ema.conf` override (an empty `ssl-ca =` there
clears the default; `ssl-crl` needs `ssl-ca`):

```ini
ssl-ca  = /etc/ssl/ema-ca.pem
ssl-crl = /etc/ssl/ema-crl.pem
```

Provision as usual; every instance ema creates on this host now carries the CA
and the CRL:

```bash
ema create srv/<name>-<GUID>
grep -E 'ssl-ca|ssl-crl' /etc/<db>/my.cnf
# ssl-ca  = /etc/ssl/ema-ca.pem
# ssl-crl = /etc/ssl/ema-crl.pem
```

## The file

`etc/ema.default.conf` and `etc/ema.conf` are the **repo-root** `etc/` — the
siblings of `etc/reuter.ini`, resolved against the repo root like every other
path ema uses, *not* the system `/etc`. ema merges the two, the override last:
the committed `etc/ema.default.conf` (tracked, read as a fallback so a plain
checkout works) then the optional git-ignored `etc/ema.conf` (the override).
The `ssl-ca` value is an absolute path on the host (typically under the system
`/etc/ssl`), chosen by the consumer:

```ini
ssl-ca = /etc/ssl/ema-ca.pem
```

Three-state resolution for `ssl-ca`:

- **absent** (in both files) → no `ssl-ca` line: plain TCP, unchanged;
- **empty in the override** (`ssl-ca =`) → no `ssl-ca` line, explicitly clearing
  a defaulted value;
- **present non-empty** → the CA path: it must be an **absolute** path (a
  relative or quoted-`~` value is a loud error at read), and the file must exist
  at `ema create` time (a loud error at consume).

`EMA_SSL_CA` overrides the key (tests, one-off runs). An unreadable or malformed
file is treated as absent, as before.

## What ema writes

When the values are set, `_prod_write_conf` appends `ssl-ca = <path>` and
`ssl-crl = <path>` to the instance's `[mysqld]` block
(${EMA_PROD_CONF_DIR:-/etc}/<db>/my.cnf), beside `bind-address`. They are
written for **every** prod instance on the host, primary or replica, because
they are host-level rather than package keys. At consume time (`ema create`),
ema checks that the CA and CRL files exist — the operator installs them out of
band before provisioning.

The write happens at **first provision** only: an instance's `my.cnf` is
authoritative and never rewritten, so an instance that already exists keeps its
file — add the line by hand to give it the CA.

## Revocation (ssl-crl)

`ssl-crl` is the server's Certificate Revocation List: when set, ema writes
`ssl-crl = <path>` into the instance's `[mysqld]` beside `ssl-ca`, and the
server checks every presented client certificate against it. A revoked
certificate is rejected even though it still chains to the CA — revocation
without rotating the CA.

Resolution mirrors `ssl-ca`:

- **absent** (in both files) → no `ssl-crl` line: the instance verifies client
  certs but does not check revocation;
- **empty in the override** (`ssl-crl =`) → no `ssl-crl` line, explicitly
  clearing a defaulted value;
- **present non-empty** → the CRL path: it must be **absolute** (loud error at
  read), `ssl-ca` must also be set (a CRL with no CA is meaningless — loud
  error at read), and the file must exist at `ema create` time.

`EMA_SSL_CRL` overrides the key (tests, one-off runs).

The CRL is read **at server startup only** (MariaDB's `ssl-crl` is a read-only
startup variable): `FLUSH SSL` reloads the CA and server key but **not** the
CRL, and there is no hot reload. Applying a new CRL means regenerating it,
installing the file at the configured path, and restarting the instance — a
rare event, reserved for an actual revocation.

The CRL is **consumer data**: generating it (`openssl ca -revoke <serial>` then
`openssl ca -gencrl`) is a standard `openssl ca` workflow on the consumer's
offline CA machine, which keeps the `index.txt` ledger a CRL is generated from.
ema never generates or rotates the CRL; it only records the path. Because the
CA machine is offline and CRLs expire (typically 30 days), renewal is an
operator-scheduled task, not a cron on the CA.

## Scope

- the CA and CRL files are the operator's: installed out of band, never
  copied or generated by ema — the CRL is regenerated on the offline CA machine
  (`openssl ca`), and ema records the path only;
- the client certificate/key material and the `REQUIRE X509` declaration are
  consumer data, like every other account attribute;
- **client-side server verification** and the replication channel's client-cert
  keys (`MASTER_SSL_CERT`/`MASTER_SSL_KEY`) are deferred: only the server half
  of mutual TLS lands here.
