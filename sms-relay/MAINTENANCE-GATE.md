# Relay maintenance admission fence — predeployment candidate

This gate is **not an all-purpose deployment procedure**. The operator must
first prove every active entry point runs the instrumented version, including
PHP-FPM code and every independently scheduled worker. The initial installation
cannot itself be protected from old, ungated processes by this lock. Inventory
and drain old process trees before trusting it; a nonblocking exclusive lock
cannot see code that never acquired a shared lock. Do not change the shared
crontab. Do not deploy a queue/schema or format change on the strength of this
file alone.

## Operation and invariants

* Pre-create `maintenance.lock` as a single-link regular file at the parent of
  the relay code directory (the code directory contains `maintenance-lock.php`).
  It is private, on the same local filesystem as all relay processes, and must
  be **the same inode** for FPM, cron and the maintenance operator. Never
  delete, rename, replace, rotate or atomically deploy this lock file. The relay
  FPM identity needs read access; the master/cron identity needs read access and
  the operator needs permission to acquire an exclusive advisory flock. Parent
  directories and file permissions/ACLs must prevent unrelated application
  identities from replacing or reading it. Prove effective access with the
  actual runtime identities; ACL text alone is insufficient. Keep the lock out
  of every document root. Do not grant an application identity directory write
  access merely to grant file access. Missing/unreadable/symlinked/hard-linked
  locks fail closed rather than creating a new inode.
* Signed application API requests and all five relay CLI entries (including the aggregate monitor) acquire `LOCK_SH|LOCK_NB` before
  reading configuration or touching state, holding it until execution ends.
  Rejected requests return 503 with Retry-After; workers and monitor exit 75 without running.
  The monitor emits an unhealthy JSON maintenance result, not a healthy snapshot.
  A flock disappears when its holder dies; an existing unlocked file is normal,
  not a stale lock to remove. Never assume that a lock file's presence means a
  holder exists. Replacing/unlinking an actively locked inode breaks fencing.
* A reviewed plan may run synchronously in the operator process:
  `php /absolute/path/to/code/maintenance-exclusive.php /absolute/path/to/plan.php`.
  It immediately exits 75 if any request/worker is still active or the lock is
  unavailable; retry after verifying the cause. The plan executes only after
  `LOCK_EX|LOCK_NB` succeeds and holds that lock until the PHP process exits.
  The plan must perform all state-changing work in that process: do not spawn,
  detach or delegate work that can outlive the lock holder. Never terminate the
  holder while a child or independent writer is operating. Require explicit
  idempotent recovery, durable backups, queue compatibility and rollback rules
  in the plan; this wrapper supplies none of those safeguards.
* The WP SMS bridge is intentionally not locked: it uses a different app
  identity and retries relay 503 responses. This is **bounded**, not guaranteed
  delivery: WP pending jobs expire after one hour, retries/scheduling can fail,
  and failure requires staff follow-up. Lead records/email are separate from
  pending SMS. Pause length and recovery must account for expiry; do not claim
  no SMS loss or automatic eventual delivery. Enrollment and revocation receive
  503 while locked. Exact POST `/v1/twilio` bypasses the fence but remains
  signature authenticated. SQLite serializes suppression and migration writes;
  exceeding its five-second busy timeout can still return 503, and provider retry
  is not guaranteed. Keep migrations short and test concurrent signed STOP on a
  disposable database. A provider-accepted message cannot be recalled.
* Switches alone do not gate maintenance or API traffic. Fencing covers only
  cooperating entry points; private scripts, old PHP-FPM/OPcache code, direct
  database clients and in-flight pre-instrumentation code are not fenced.
  Simulated tests do not prove actual FPM identity, cross-user permissions,
  filesystem locking, OPcache refresh or safe first-time installation.

## Local verification

Run `python3 tests/sms-maintenance-gate.py`; it copies the relay to a disposable
private directory and uses loopback and a fake provider, not Twilio. For the
other tests using this repo as the application root, make an empty
`maintenance.lock` in a disposable copy of that repo and use only synthetic
configuration. `python3 tests/wp-sms-loopback.py` exercises a stubbed WP client
against the actual local API 503 and retries with the gate released; it is not a
real WP-Cron, TLS, provider, or server-identity test.
