# Central SMS relay — prelaunch, disabled

This directory now contains a separately hosted relay HTTP front controller, SQLite queue, Twilio PHP SDK sender and signed inbound webhook. **It is not connected to WordPress and is not approved for staging, production or live SMS.** No credentials, messages, client settings or deployed sites were changed. The worker refuses to send unless `live_send_enabled` is explicitly the JSON boolean `true`; leave it false. The worker switch does not disable Twilio's automatic keyword replies or the webhook's HELP TwiML, so do not configure a live sender or webhook. This is an implementation for isolated evaluation, not proof of real consent, carrier compliance, delivery, operational readiness or provider approval.

Taggart is the intended sender to consenting business administrators. The alert includes the lead's name, phone and service; it must not go to the homeowner. Heritage and Assurance are intended to be grandfathered without subscription charges; no billing, grandfathering records, caps or prices have been configured by this code. Their existing settings are untouched. Do not infer consent from prelaunch interest, an old email-to-carrier number or a screenshot. Twilio must confirm the registered use case permits Taggart to send to admins of multiple businesses.

## Runtime and boundaries

PHP 8.1+ with PDO SQLite, sodium, curl and Composer is required. Install locked dependencies separately using `composer install --no-dev` in this directory. Serve **only** `public/` behind HTTPS using `public/index.php` as the front controller. Keep source, Composer vendor, JSON configuration, SQLite database (including journal/WAL files), backups and secrets outside the public document root; use restricted owner/group permissions and an isolated service account. The PHP built-in server is strictly for loopback tests, not production. Pass the private JSON file path in `IEB_RELAY_CONFIG`; do not put values in URLs, command history, logs or the plugin. The HTTP process and worker must use the same durable local SQLite database and key on one host. Do not use independent replicas, ephemeral filesystems, network filesystems with unreliable locks, or restore stale backups without reconciling suppression, nonce and idempotency history. Back up SQLite consistently and protect/encrypt backups. Install schema explicitly before accepting requests; the HTTP server does not auto-create it.

Private config shape (placeholders are deliberately invalid and must not be committed):

```json
{
  "database": "/private/relay/queue.sqlite",
  "encryption_key": "64-lowercase-hex-random-bytes-placeholder",
  "installations": {"installation_id": "64-lowercase-hex-random-secret-placeholder"},
  "live_send_enabled": false,
  "twilio": {
    "account_sid": "AC-account-sid-placeholder",
    "auth_token": "private-auth-token-placeholder",
    "sender": "+15555550100",
    "webhook_url": "https://relay.example.com/v1/twilio"
  }
}
```

The key and each installation secret must be independent 32-byte random values represented by 64 lowercase hex characters. Use a secret manager and authenticated provisioning; these sample values are not usable credentials. There is no provisioning or admin endpoint, secure rotation/migration procedure, or automated operations. Internally initialize `IebSmsQueue::install()` and `ieb_auth_install_schema()` using the same PDO and encryption key, then `configureInstallation(id, cap, enabled)` only through an approved privileged operator workflow. Cap must be a positive explicit integer; it is not a proposed customer price or allowance. Installation HMAC keys are currently read from private configuration, so revocation requires securely updating it. Installation identifiers have the auth restriction 8–64 alphanumerics, underscore or hyphen. Database write failures fail closed. A real deploy needs a deployment-specific, reviewed provisioning, rotation, restore, deletion and retention runbook.

## HTTP contract

Only POST requests with no query string are accepted. Installation requests use `Content-Type: application/json` and headers `X-Installation`, `X-Timestamp`, `X-Nonce`, `X-Signature`. The signature is lowercase hex HMAC-SHA256 using the binary installation key over newline-separated `IEB1`, installation ID, uppercase method, exact path, Unix epoch timestamp, 32-lowercase-hex random nonce, and lowercase SHA-256 hex of the exact raw body; there is no final newline. An authenticated timestamp must be within 300 seconds. Every attempt needs a fresh nonce, including a retry; accepted nonces are persisted atomically through timestamp + 300 seconds. Use a trusted synchronized clock.

* `/v1/consent`: `{ "phone": "+E164", "actor": "admin_id", "notice": "notice_version" }`
* `/v1/revoke`: `{ "phone": "+E164", "actor": "admin_id" }`
* `/v1/events`: `{ "event": "event_id", "phone": "+E164", "lead": { "name": "...", "phone": "...", "service": "..." } }`

The phone on an event is the opted-in administrator; the lead phone is included in the body. Actor, notice and event IDs are restricted identifiers; consent records are installation-scoped, and global STOP blocks every installation. The API verifies the installation secret, **not the administrator's identity, affirmative consent, phone ownership, or notice contents**. A compromised installation can submit false consent. Do not connect a client or enable sending until authenticated, CSRF-protected per-person enrollment, notice version, provenance, number ownership and revocation have been independently designed, implemented and reviewed. Consent on a stopped number fails with 409; START does not restore it. The queue rejects unconsented, disabled and stopped recipients.

A unique `(installation, event, recipient)` and keyed payload hash persist across process restarts. A repeat with identical payload returns `duplicate`; changed payload returns 409. IDs, phone and payload cannot be reused to imply delivery. Requests with invalid authentication/replay return 401. A 200 `queued` is not a provider acceptance or delivery. Ordinary errors and infrastructure failures are generic and omit data. There is no status API or delivery receipt handling.

## Provider and STOP semantics

`worker.php` is CLI-only and processes at most one item. It is disabled unless the explicit config boolean is true; **do not enable it now**. When enabled, it uses Twilio's official SDK with HTTPS, a 2-second connect and 4-second total timeout. A valid provider message SID is recorded as `accepted`, not delivered. All exceptions and uncertain responses become `unknown`, reserve quota and are **never automatically retried**, including HTTP errors; operators need a reviewed reconciliation procedure. Before any provider invocation the queue commits `unknown` and reserves quota, so process death or database commit failure after a possible send cannot generate an automatic duplicate. Capped events are terminal and caps count accepted/unknown attempts by UTC month, not billed SMS segments.

Final consent, enabled status and suppression are checked in a SQLite `BEGIN IMMEDIATE` transaction that remains locked across the bounded provider call and result commit. STOP, local revoke and entitlement updates use the same database write lock. Thus a STOP that commits first prevents the later dispatch; if the dispatch wins, STOP cannot commit/acknowledge until the provider call releases the lock. This is a linearization guarantee on this one database, **not a guarantee against a send already in flight**, and it cannot determine the instant Twilio or a carrier received the original STOP. When the database is busy, webhook errors return 503 rather than falsely acknowledging suppression; configure and verify provider retry/alerting and reconcile failures. Holding a global SQLite write lock over network I/O limits throughput and can delay or fail inbound STOP; no production load, failover or provider retry behavior has been verified.

The `/v1/twilio` webhook accepts form-encoded POST only and validates all supplied fields with the official SDK `RequestValidator` using the configured exact externally visible HTTPS URL and auth token, not the Host/forwarded headers. Configured URL must be exactly the public `/v1/twilio` URL with no query string. It also checks the configured AccountSid and To sender, validates From and MessageSid, rejects malformed/duplicate form fields and rejects unsupported shared-key signature settings. Signed STOP synonyms or `OptOutType=STOP` suppress the phone across installations and deactivate their consents. Webhook MessageSid hashes are stored durably in the same transaction for STOP replay idempotency. `START` intentionally does not clear suppression or create business consent; there is no production re-enrollment workflow yet. Basic HELP returns Taggart support at `support@taggartmediagroup.com`; an Advanced Opt-Out `OptOutType` suppresses an extra reply because Twilio has already responded. Verify Twilio's actual keyword configuration, HELP/STOP responses, inbound routing, retries and ordering for the chosen number before any launch; default provider replies may otherwise cause duplicate HELP replies. Unknown/non-keyword inbound messages receive empty TwiML. Shared-key webhook signing and webhook JSON are not supported.

Never log request/response bodies, numbers, lead details, tokens, HMAC keys, signature headers or provider exceptions in PHP, proxies, web server, APM, worker logs or backups. This code intentionally emits generic errors and non-PII worker states, but cannot configure or guarantee infrastructure logging. Enforce request size limits, authentication abuse/rate limits, TLS, access controls, alerting, secure secret handling, retention and incident response separately. Twilio can itself retain message content according to its account settings.

## Local verification

From the repository root, after installing the locked Composer dependencies:

```sh
php tests/sms-relay-auth.php
python3 tests/sms-relay-auth-concurrency.py
php tests/sms-relay-queue.php
php tests/sms-relay-consent-audit.php
php tests/sms-relay-stop-order.php
python3 tests/sms-relay-queue-concurrency.py
python3 tests/sms-relay-race.py
php tests/sms-relay-api.php
php tests/sms-relay-webhook.php
python3 tests/sms-relay-service-http.py
python3 tests/sms-relay-twilio.py
python3 tests/sms-relay-worker-disabled.py
python3 tests/sms-relay-http.py
php tests/conditional-pricing.php
php tests/test-email-subject.php
```

The new HTTP test uses the actual front controller and a local synthetic SQLite database, restarts the PHP server to test durable nonce/event idempotency, and submits synthetic signed/tampered webhooks. The adapter test runs the actual SDK sender and queue against a loopback fake provider; it never contacts Twilio. The multiprocess race test holds the fake provider in flight and verifies STOP cannot be acknowledged until it finishes, then suppresses the next item. No test proves carrier delivery, real Twilio behavior, a real WordPress client, rate limiting, hosted security, actual consent, an enabled live worker or production performance. Existing local fixture tests remain experimental, not deployment endpoints.

## Before any staging integration

Obtain security and operations review; approve hosting/TLS, secrets, backups, data retention, worker scheduling, abuse controls and unknown-outcome reconciliation. Implement and validate real consent and client UI, durable provisioning/revocation/rotation and explicitly grandfathered entitlements; obtain written Twilio multi-business approval and complete number/use-case registration and messaging terms. Reconcile plugin changes against freshly deployed staging, back up per site workflow and keep SMS disabled. No live SMS, real test lead, client email, production deployment, purchases or changes to existing jobs without separate authorization.
