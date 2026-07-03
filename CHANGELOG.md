# Changelog

## 1.0.13 - WordPress.org preparation

- Added a WordPress.org plugin directory `readme.txt`.
- Updated the dashboard done-for-you setup CTA for the public landing page.
- Added launch landing page copy draft for review.
- Removed the GitHub release updater and custom plugin-details override for WordPress.org submission.

## 1.0.12 - Release packaging

- Added release-asset ZIP support for cleaner WordPress plugin updates.

## 1.0.11 - Update metadata and icon

- Added branded plugin icon assets for WordPress update/details screens.
- Added update metadata for WordPress/PHP compatibility.
- Corrected the 2x plugin icon asset path.

## 1.0.10 - Security hardening

- Restricted sensitive lead, export, notification, and integration screens to admin-level capability.
- Masked saved Klaviyo private API keys and sanitized integration settings.
- Added nonce checks for lead CSV export, cropped-image upload, and lead-notes autosave.
- Added CSV formula-injection protection for lead exports.
- Added field-specific sanitization for notification and email settings.
- Reduced PII in debug logs.
- Restricted form preview URLs to users who can edit the form.
- Recalculate estimate totals server-side from the saved form definition instead of trusting client-submitted price values.

## 1.0.9 - Main plugin file rename

- Renamed the main plugin file from `hvac-quote-generator.php` to `instant-estimate-builder.php` for a fully clean public plugin identity.
- Preserved existing data compatibility identifiers, post types, shortcodes, and admin slugs so existing forms/leads continue working.

## 1.0.8 - Fresh plugin identity

- Updated the GitHub updater slug to `instant-estimate-builder` for clean new installs.
- Kept legacy plugin-information compatibility for old `hvac-quote-generator` update-detail requests.

## 1.0.7 - Admin polish and public release prep

- Added GPL-2.0-or-later license metadata for public distribution and future WordPress.org compatibility.
- Polished the Estimate Forms, Leads, Lead Details, Notifications, Integrations, and Support admin pages with the Instant Estimate Builder dashboard visual system.
- Added branded admin slugs for estimate forms, leads, integrations, notifications, and support while preserving legacy internal identifiers for existing installs.
- Removed the visible license flow now that Instant Estimate Builder is free to use.
- Kept legacy compatibility redirects so bookmarked old admin URLs continue to work.

## 1.0.6 - Phase 1 rebrand

- Rebranded user-facing plugin identity from HVAC Quote Generator to Instant Estimate Builder.
- Updated plugin author branding to Taggart Media Group.
- Updated WordPress admin menu and dashboard labels from quote forms to estimate forms.
- Added a modernized plugin dashboard with lead snapshot, builder quick links, and optional $299 done-for-you setup positioning.
- Added generic plugin constants while preserving legacy HGM constants for backward compatibility.
- Kept legacy post types, AJAX actions, shortcodes, and internal identifiers in place to avoid breaking existing installed sites.
