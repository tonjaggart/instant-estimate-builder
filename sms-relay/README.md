# SMS relay local experiments — NOT A SERVICE

Status: **incomplete; not deployable; do not enable SMS**. This directory contains authentication and queue experiments in a plugin feature worktree. The loopback HTTP router in `tests/` is a synthetic fixture, not a service. There is no WordPress client or user interface, authenticated enrollment, actual provider, webhook signature validation, HELP handling, operational hosting or real delivery. Nothing here changes the current plugin behavior. The relay must be separately packaged and hosted, outside the WordPress plugin and web document root; no Twilio credential may be put in the plugin or this repository.

## Local queue experiment

`queue.php` adds an SQLite ledger with encrypted recipient/lead payloads, keyed phone and payload indexes, per-installation consent history and revocation, cross-installation STOP state, idempotent enqueue/conflict detection, explicit caps and a fake-provider callback. Events reserve a quota slot and are marked `unknown` *before* a provider call: a crash, exception, malformed callback result or uncertain outcome is never automatically retried. Only an explicit `retryable` result representing a **proven non-acceptance** can be retried; attempts are capped at three. The test cap is synthetic, not a proposed customer allowance or policy. Caps are counted by UTC month; capped events are terminal in this prototype.

**Do not attach a real sender.** The callback and consent methods are trusted internal interfaces, not authorization or ownership verification. There is an unresolved race between the final consent check and a provider call; inbound STOP during that interval can arrive before a send but too late to prevent it. A timestamp comparison on `start` prevents older/tied synthetic calls from undoing STOP but does not protect against a replayed signed webhook with a new local receipt time. Real provider behavior, webhook idempotency/order and message ownership must be designed and tested before use. Configuration, secret storage/rotation, migrations, data retention/deletion, operational recovery of unknown outcomes, quota policy and abuse controls are absent. The audit preserves local consent changes but is not proof of actual user consent or phone ownership. A cap of zero is deliberately rejected; no paid entitlements are configured. The same secret currently derives encryption and keyed indexes, so key rotation also requires an explicit migration design.

The test fixture creates a local synthetic installation with a hardcoded test cap and accepts self-reported consent under an installation HMAC. It is loopback-only and must never be deployed or mistaken for a secure enrollment API. It does not validate provider signatures, connect to WordPress or send SMS.

## Verified baseline

On November 25, 2026, read-only Cloudways SSH/WP-CLI identified Heritage staging at `https://heritage.taggartmediagroup.com`, active plugin `1.0.13-conditional.1`, and `blog_public=0`. Deployed `handle-form.php` SHA-256 `b4030890be495724c59361ff3cd0054553d419dbcccbff190c93394f9591a8cc` matches `fe72b7f` in the conditional-pricing worktree. The deployed Notifications file matches `b0bc938` in the separate planned-SMS preview worktree **ignoring line endings**, but differs byte-for-byte. Thus no single one of those local branch tips is an exact representation of staging. No staging files, options, recipients, or provider configuration were changed.

The existing implementation has a separate legacy email-to-carrier-gateway path; prelaunch interest is explicitly not consent. Do not treat a stored gateway number, preview, or preference as consent for Twilio.

Cloudways SSH access, PHP 8.2.36 CLI, PHP curl/openssl/PDO SQLite/SQLite/sodium modules, Python 3, and crontab executable were observed. This is **not** verification of a separate, durable, secure HTTPS relay deployment: isolated hosting/app, reverse proxy, DNS/TLS, worker scheduling, backups, secrets storage, access control, retention, monitoring, and maintenance remain unresolved. Existing scheduled jobs must not be modified or exposed.

## Authentication experiment

`auth.php` implements a PHP HMAC-SHA256 canonical signature with a generated 32-byte random secret, installation identifier, method, path, epoch seconds, nonce and SHA-256 digest of the exact request body. The signature is lower-case hex. The canonical bytes are newline-separated `IEB1`, installation id, uppercase method, exact normalized path, timestamp, 32-hex nonce and lower-case body digest, with no trailing newline. URL query parameters are deliberately unsupported. Only canonical restricted values are accepted to prevent newline or normalization ambiguity. Treat the generated key as a bearer-equivalent secret: create it securely per installation, provision over authenticated TLS, protect it at rest and support revocation/rotation. Generation alone does not prove secure provisioning.

For a shared persistent SQLite database on one host, verified signatures within ±300 seconds are recorded with an installation-scoped unique nonce until the authenticated timestamp + 300 seconds. Invalid signatures do not consume a nonce. A fresh signed nonce is required for every retry. Database errors propagate; an eventual HTTP adapter **must** catch them and fail closed, and must never send on errors. Persist this table across restarts, use a consistent trusted clock, prevent unsynchronized multi-host replicas, and test locking/storage failure modes before deployment. For multi-host scale use an atomic shared database, not per-process or per-instance SQLite.

**Nonce replay protection is not event idempotency.** A future relay must atomically enforce a unique installation/event/recipient identity, detect a changed payload under the same identity, and make retry semantics explicit. A network timeout after Twilio accepts a send is an ambiguous outcome; blindly retrying can send duplicates. Do not claim exactly-once delivery or assume provider idempotency without verification. Also enforce caps atomically at dispatch and re-check consent/STOP immediately before sending.

## Local checks

From the worktree root:

```sh
php tests/sms-relay-auth.php
python3 tests/sms-relay-auth-concurrency.py
php tests/sms-relay-queue.php
php tests/sms-relay-consent-audit.php
php tests/sms-relay-stop-order.php
python3 tests/sms-relay-queue-concurrency.py
python3 tests/sms-relay-http.py
php tests/conditional-pricing.php
php tests/test-email-subject.php
php -l sms-relay/auth.php
```

The authentication test uses an in-memory SQLite database and synthetic payload only. The separate concurrency test opens one temporary SQLite file from 24 local PHP processes and checks that exactly one accepts the same signed request. Queue tests exercise synthetic consent, STOP/START, encrypted payloads, duplicate/conflicting events, retry, caps, disabled installation and audit history. Another test runs 12 competing PHP processes against SQLite, then kills a fake-provider worker after it claims an event and checks the unknown state reserves quota. The HTTP test exercises a loopback-only fixture with signed requests and a separate fake worker. No test sends a text or uses a real provider. These are **not** real WordPress pipeline, penetration, Twilio SDK, signed webhook, production load or real-delivery tests.

## Required before staging integration

1. Decide and verify separate central runtime, TLS domain, database and worker operations; budget/hosting choice is not approved merely by SSH access. Ensure request/access/error logs and monitoring do not record phone numbers, message bodies, secrets, or lead PII; define retention and incident response.
2. Build the WordPress admin workflows with authenticated CSRF-protected saves and separate affirmative evidence for **each consenting phone user**. Number changes and withdrawals must not transfer consent. Carry versioned disclosure and provenance securely to relay; prelaunch interest and old carrier recipients are not consent. Determine a reliable process to verify number ownership and enroll multiple admins.
3. Build actual relay API, rate limits, authenticated installation provisioning, revocation and rotation, authorization, atomic idempotent queue/usage, fail-closed entitlement records (explicitly grandfather Heritage and Assurance), consent storage, per-recipient global suppression, retries with ambiguous-outcome handling and a worker. Prices and paid caps remain undecided; do not make up allowances.
4. Integrate the official Twilio SDK with credentials server-side and use the SDK's webhook signature validator, exact externally configured URL and all parameters. Implement and test STOP/HELP and state across every installation sharing the sender, account for Twilio automatically handling standard keywords, and avoid double replies when Advanced Opt-Out reports `OptOutType`. START must not create blanket business-specific consent. See https://www.twilio.com/docs/usage/webhooks/webhooks-security and https://www.twilio.com/docs/messaging/tutorials/advanced-opt-out.
5. Test end-to-end with a fake provider, forged/signed webhooks, tampering, duplicate/reordered STOP, concurrent caps, replay, crashes, queues, retries, logging and plugin email independence. Require real security/operational review and consent/terms approval. Obtain written Twilio confirmation that the registered sender/use case covers admins at multiple businesses; number approval alone is not that confirmation.
6. Reconcile both local branches against the **fresh** deployed staging files, back up according to the site workflow, and only then stage a deliberately disabled build. No live SMS, real test leads, client emails, production deployment, activation, or purchases without separate authorization.
