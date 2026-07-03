# WordPress.org Submission Checklist

## Current Status

Prepared in branch:

`feature/wporg-productization-v1.0.13`

## Done

- Public GitHub repository exists.
- GPL-2.0-or-later plugin header exists.
- Main plugin metadata includes Requires/Tested/Requires PHP.
- Legacy shortcodes and identifiers are preserved for existing installs.
- License flow is compatibility-only and no longer blocks free use.
- WordPress.org-style `readme.txt` added.
- Dashboard done-for-you setup CTA points to the published production landing page.
- Landing page copy draft added in `docs/done-for-you-landing-page-copy.md`.
- Production landing page exists at `https://taggartmediagroup.com/instant-estimate-builder/`.
- Production WooCommerce setup product exists and checkout is wired from the landing page.
- GitHub release updater removed from the plugin entrypoint so WordPress.org-hosted installs use WordPress.org updates.
- WordPress.org banner and icon assets exist in `assets/`.

## Still Needed Before WordPress.org Submission

1. Add plugin screenshots:
   - Dashboard
   - Form builder
   - Frontend multi-step form
   - Lead inbox
   - Notification settings

2. Run a final security/compliance review:
   - Nonces for state-changing actions
   - Capability checks
   - Sanitization before save
   - Escaping before output
   - No hidden tracking/calling home
   - No private client references
   - No credentials/secrets

3. Submit the plugin through WordPress.org developer portal.

4. After approval, push to WordPress.org SVN:
   - `/trunk`
   - `/tags/1.0.13`
   - `/assets`

## Recommended Submission Positioning

Short description:

`Create multi-step instant estimate forms for local service businesses with lead capture, notifications, and lead management.`

Primary tags:

- estimate form
- quote form
- lead generation
- local services
- multi step form

## Key Compliance Notes

- The plugin must remain useful without purchasing setup help.
- Done-for-you setup can be promoted as optional, but not as a required account or license.
- Do not silently collect site/admin/lead data for marketing.
- Do not imply estimate ranges are final contractual quotes.
