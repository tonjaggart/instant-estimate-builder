# Central SMS relay — prelaunch, disabled

This directory contains a separately hosted relay HTTP front controller, SQLite queue, Twilio PHP SDK sender and signed inbound webhook. The plugin contains an experimental server-side WordPress client; it is unconfigured by default and is not approved for staging, production or live SMS. No credentials, messages, client settings or deployed sites were changed. The worker refuses to send unless `live_send_enabled` is explicitly the JSON boolean `true`; leave it false. The worker switch does not disable Twilio's automatic keyword replies or the webhook's HELP TwiML, so do not configure a live sender or webhook. This is an implementation for isolated evaluation, not proof of real consent, carrier compliance, delivery, operational readiness or provider approval.

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
  "verification_send_enabled": false,
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

* `/v1/consent`: `{ "phone": "+E164", "actor": "admin_id", "notice": "notice_version" }` — **disabled**, returns 403 for an authenticated well-formed request; a signed installation assertion is not ownership verification.
* `/v1/verification/request`: `{ "request_id": "32-lowercase-hex-random", "phone": "+E164", "actor": "admin_id", "notice": "admin-sms-v1", "opt_in": true }`. The random 128-bit request ID is generated by the client and must remain unchanged for retries of the identical request. A 202 means only that the request was processed, not that it was delivered or eligible. An ID reused with changed fields conflicts.
* `/v1/verification/confirm`: `{ "request_id": "same-id", "code": "six-digit-code" }`. A correct unexpired code for that installation atomically records consent; 409 covers wrong, expired, exhausted, suppressed, revoked, already-used and unknown challenges alike. Do not log the code. Every HTTP attempt, including a retry, needs a fresh authentication nonce.
* `/v1/revoke`: `{ "phone": "+E164", "actor": "admin_id", "request_id": "optional-verification-id" }`. A supplied 32-hex request ID is durably tombstoned, so a racing verification request with that ID arriving afterward cannot enqueue a code. Old clients without an ID still revoke existing consent and challenges but cannot prevent a not-yet-arrived request.
* `/v1/events`: `{ "event": "event_id", "phone": "+E164", "lead": { "name": "...", "phone": "...", "service": "..." } }`

The phone on an event is the opted-in administrator; the lead phone is included in the body. Actor, notice and event IDs are restricted identifiers; consent records are installation-scoped, and global STOP blocks every installation. Verification demonstrates possession of a code delivered to that number, not the administrator's identity or that the client actually displayed a notice. An untrusted client can still falsely claim that it showed the notice. The original proposed `admin-sms-v1` wording was “I agree to receive automated text messages from Taggart Media Group about new estimate leads at this number. Message frequency varies. Message and data rates may apply. Reply STOP to opt out or HELP for help. Contact support@taggartmediagroup.com.” The new WordPress client sends `admin-sms-v2` for the distinct preview-based checkbox in `includes/sms-client.php`, including the own-number affirmation and links to terms and privacy. Both versions are accepted for isolated tests; do not conflate their provenance. Both wording and actual collection workflow require legal/provider review before use. The unsafe direct-consent endpoint remains disabled. The internal queue consent method remains available to trusted local code and tests; it is not an enrollment interface. Existing consent rows are not automatically revoked. START does not restore consent. Do not enable sending until the UI, provenance and operational controls have been independently reviewed.

Verification codes are generated using `random_int`, stored as keyed HMACs, expire after 10 minutes and allow five wrong attempts. To permit asynchronous delivery, the code and recipient are also temporarily encrypted together in the queued payload; the ciphertext is cleared on claim, cancellation or queued expiry. SQLite journals, snapshots and backups may retain older ciphertext, so protect them and implement reviewed retention. The worker claims as `unknown` and clears ciphertext before provider invocation; uncertainty/crash never triggers an automatic resend. The code may be confirmed after an uncertain send because it might have reached the recipient. Limits are three requests per number globally and 20 per installation per rolling hour; blocked requests receive the same 202 and are recorded as blocked, and count toward limits. These are preliminary controls, not a substitute for infrastructure throttling, database retention, abuse monitoring and review. STOP or revoke cancels outstanding challenges. The final STOP/entitlement check and provider call use the same SQLite write lock as STOP; a STOP committed first prevents sending and a STOP arriving during a call cannot be acknowledged until it finishes. Verification never clears global STOP. Re-enrollment after STOP requires an independently reviewed, support-mediated provider opt-in/unblock procedure followed by a new explicit business opt-in and fresh verification; no automatic unblock is implemented.

A unique `(installation, event, recipient)` and keyed payload hash persist across process restarts. A repeat with identical payload returns `duplicate`; changed payload returns 409. IDs, phone and payload cannot be reused to imply delivery. Requests with invalid authentication/replay return 401. A 200 `queued` is not a provider acceptance or delivery. Ordinary errors and infrastructure failures are generic and omit data. There is no status API or delivery receipt handling.

## Provider and STOP semantics

`worker.php` is CLI-only and processes at most one alert. `verification-worker.php` is a separate CLI-only worker and needs both `live_send_enabled` and `verification_send_enabled` to be explicit JSON `true`. Both are disabled by default; **do not enable them now**. When enabled, they use Twilio's official SDK with HTTPS, a 2-second connect and 4-second total timeout. A valid provider message SID is recorded as `accepted`, not delivered. All exceptions and uncertain responses become `unknown` and are **never automatically retried**, including HTTP errors; alert attempts reserve quota and operators need a reviewed reconciliation procedure. Before any provider invocation the queue commits `unknown`, so process death or database commit failure after a possible send cannot generate an automatic duplicate. Capped alerts are terminal and caps count accepted/unknown alert attempts by UTC month, not billed SMS segments.

Final consent, enabled status and suppression are checked in a SQLite `BEGIN IMMEDIATE` transaction that remains locked across the bounded provider call and result commit. STOP, local revoke and entitlement updates use the same database write lock. Thus a STOP that commits first prevents the later dispatch; if the dispatch wins, STOP cannot commit/acknowledge until the provider call releases the lock. This is a linearization guarantee on this one database, **not a guarantee against a send already in flight**, and it cannot determine the instant Twilio or a carrier received the original STOP. When the database is busy, webhook errors return 503 rather than falsely acknowledging suppression; configure and verify provider retry/alerting and reconcile failures. Holding a global SQLite write lock over network I/O limits throughput and can delay or fail inbound STOP; no production load, failover or provider retry behavior has been verified.

The `/v1/twilio` webhook accepts form-encoded POST only and validates all supplied fields with the official SDK `RequestValidator` using the configured exact externally visible HTTPS URL and auth token, not the Host/forwarded headers. Configured URL must be exactly the public `/v1/twilio` URL with no query string. It also checks the configured AccountSid and To sender, validates From and MessageSid, rejects malformed/duplicate form fields and rejects unsupported shared-key signature settings. Signed STOP synonyms or `OptOutType=STOP` suppress the phone across installations and deactivate their consents. Webhook MessageSid hashes are stored durably in the same transaction for STOP replay idempotency. `START` intentionally does not clear suppression or create business consent; there is no production re-enrollment workflow yet. Basic HELP returns Taggart support at `support@taggartmediagroup.com`; an Advanced Opt-Out `OptOutType` suppresses an extra reply because Twilio has already responded. Verify Twilio's actual keyword configuration, HELP/STOP responses, inbound routing, retries and ordering for the chosen number before any launch; default provider replies may otherwise cause duplicate HELP replies. Unknown/non-keyword inbound messages receive empty TwiML. Shared-key webhook signing and webhook JSON are not supported.

Never log request/response bodies, numbers, lead details, tokens, HMAC keys, signature headers or provider exceptions in PHP, proxies, web server, APM, worker logs or backups. This code intentionally emits generic errors and non-PII worker states, but cannot configure or guarantee infrastructure logging. Enforce request size limits, authentication abuse/rate limits, TLS, access controls, alerting, secure secret handling, retention and incident response separately. Twilio can itself retain message content according to its account settings.

## Experimental WordPress client

The client is inert unless three server-side constants are provisioned: `IEB_SMS_RELAY_HOST` (a single lowercase DNS hostname, no scheme/port/path), `IEB_SMS_INSTALLATION` and `IEB_SMS_SECRET` (the 64-hex-character installation HMAC key). Provision privately in server-only configuration; never place keys in plugin source, admin forms, browser code, URLs or logs. The client constructs a fixed HTTPS URL with no redirects and uses `wp_safe_remote_post` to reject unsafe destinations. This is not a provisioning, rotation, credential-vault or production deployment procedure. Do not configure these constants on any site now.

Each privileged administrator must enroll their own number through an unchecked checkbox and confirm the code. A 202 request is not proof of delivery, and the WordPress verified state does not reflect later relay STOP or delivery status. On opt-out, local dispatch stops first; if the relay cannot acknowledge revocation, retry opt-out and contact support or reply STOP. Previously accepted relay jobs cannot be recalled when the relay is unreachable. The relay tombstones the verification request ID on revocation and WordPress uses conditional metadata updates; however, real WordPress user-meta creation is not known to be atomic under concurrent requests and end-to-end multisession races remain a release blocker until tested and resolved. Legacy email-to-carrier settings and their existing sender are separate and are not migrated or disabled by this code.

Lead alerts are placed in protected lead metadata as a durable pending outbox, and a WP-Cron task sends only name, phone and form title. Transient errors are retried with stable event payloads, fresh HMAC nonces and an hourly recovery sweep; relay idempotency prevents a duplicate event from being sent twice. HTTP acceptance does not prove delivery. WP-Cron depends on site traffic or a separately configured runner, and the unbounded recovery scan, concurrency, persistent object cache, large installations, database failure, retention and operations have not been validated on a real site. Data is removed from the WordPress pending outbox after relay acceptance or permanent rejection, but backups and logs may retain it; do not log bodies, codes, signatures or secrets. The separately hosted relay's encrypted payload and STOP rules still apply.

Tests run with WordPress API stubs and synthetic data: `php tests/wp-sms-client.php` and `python3 tests/wp-sms-loopback.py`. The latter translates the fixed test hostname to a loopback-only HTTP front controller in the **test transport only** and uses an injected fake provider; the production client has no HTTP or loopback exception. These tests do not exercise real WordPress core, its database, its scheduler, TLS, browser forms or delivery. Review and isolated real-WordPress testing remain mandatory. The preview branch separately edits the Notifications text panel and must be reconciled intentionally; it is not automatically merged here.

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
php tests/sms-relay-unverified-consent.php
php tests/sms-relay-verification.php
python3 tests/sms-relay-verification-http.py
python3 tests/sms-relay-verification-race.py
php tests/sms-relay-webhook.php
python3 tests/sms-relay-service-http.py
python3 tests/sms-relay-twilio.py
python3 tests/sms-relay-worker-disabled.py
python3 tests/sms-relay-http.py
php tests/conditional-pricing.php
php tests/test-email-subject.php
```

The new verification HTTP test uses the real front controller, durable SQLite, actual SDK and a loopback fake provider to verify enrollment and dispatch a synthetic alert. Other tests cover wrong codes, expiry, replay, isolation, STOP and concurrent rate limits; these do not prove identity, actual consent or carrier delivery. The older front-controller test seeds consent directly for downstream testing, and `sms-relay-http.py` exercises a separate test-only router that accepts synthetic assertions; neither is proof of enrollment. No test proves real Twilio behavior, a real WordPress client, hosted security, an enabled live worker or production performance. Existing local fixture tests remain experimental, not deployment endpoints.

## Before any staging integration

Obtain security and operations review; approve hosting/TLS, secrets, backups, data retention, worker scheduling, abuse controls and unknown-outcome reconciliation. Implement and validate the client UI and consent provenance, durable provisioning/revocation/rotation and explicitly grandfathered entitlements; obtain written Twilio multi-business approval and complete number/use-case registration and messaging terms. Reconcile plugin changes against freshly deployed staging, back up per site workflow and keep SMS disabled. No live SMS, real test lead, client email, production deployment, purchases or changes to existing jobs without separate authorization.
