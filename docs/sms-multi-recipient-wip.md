# Multiple-recipient SMS branch — deployment still requires approval

The WordPress relay client supports independently opted-in and verified phone numbers. Each number has its own verification request and consent generation. A lead creates a distinct durable job and deterministic relay event for every verified recipient; pending numbers do not receive alerts. Existing single-recipient relay state is read as recipient 1. Old carrier settings and prelaunch preferences remain stored but are not treated as consent.

The old carrier-email UI and transport are removed. Installing this branch therefore stops carrier-email alerts, including where the relay is not configured. The authenticated screenshot-preview route remains inert. A relay rejection for one number is recorded without stopping other recipients. Local opt-out prevents new local dispatch and asks the relay to revoke; if that request fails, previously accepted relay jobs may still be processed. The UI directs the recipient to retry, reply STOP, or contact support.

## Local verification

Use a fresh synthetic WordPress installation with a private, network-disabled MySQL instance and no real credentials. `tests/wp-sms-multiple-real.php` and `tests/wp-sms-multiple-race.py` require `IEB_MULTI_TEST_WP` to point to the guarded `ieb-sms-multi-recipient-test/wp` directory, with database `ieb_multi_test` on that directory's `mysql.sock`. The real test invokes WordPress's `admin-ajax.php` dispatcher in a separate CLI process, and exercises intercepted email, the actual `wp-cron.php` entrypoint, signed relay requests, per-number verification, STOP, and an in-memory SQLite relay with a fake provider. The race test uses competing WordPress processes against MySQL. Tests do not use real recipients or send live texts.

These tests do not prove live TLS, provider delivery, provider keyword behavior, or operational readiness. Do not enable live sending or deploy merely because the branch passes: provider approval for the shared sender, consent and number ownership, STOP/HELP handling, billing readiness, operational recovery, and the carrier-alert cutover still require review and authorization.
