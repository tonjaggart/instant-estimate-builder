# SMS recipient work in progress — do not deploy

This branch is not a finished multiple-recipient implementation. The relay client still stores and dispatches to **one** independently verified recipient. It does not implement adding, removing, retrying, or reporting independently for multiple numbers. Do not package, release, or deploy it to staging or production.

The carrier-email UI and transport are removed on this branch, but their saved option is not deleted. Therefore deploying this branch would stop existing carrier-email alerts even on installations without an enrolled relay recipient. The prelaunch preference is retained in storage and is not SMS consent. The authenticated, inert screenshot-preview route remains available.

Before release: implement per-number opt-in and verification, normalized deduplication, revocation and deletion; bind each event to its recipient and consent generation; handle concurrency and partial failures with durable retries; preserve the customer and administrator emails. Exercise multiple recipients using isolated WordPress/MySQL, the real AJAX and cron paths, and a fake provider. Verify STOP, stale consent, retries, idempotency, races, and the one-segment boundary. Live sending and provider policy readiness are outside the scope of these local tests.
