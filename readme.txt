=== Instant Estimate Builder ===
Contributors: taggartmediagroup
Tags: estimate form, quote form, lead generation, local services, multi step form
Requires at least: 5.4
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.13
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Create multi-step instant estimate forms for local service businesses with lead capture, notifications, and lead management.

== Description ==

Instant Estimate Builder helps local service businesses turn website visitors into estimate-ready leads.

Build guided, multi-step estimate forms for services like roofing, windows, siding, HVAC, plumbing, landscaping, remodeling, pest control, and other quote-driven local-service offers. Capture project details, show estimate ranges, send customer emails, notify your team, and manage leads from inside WordPress.

= Key Features =

* Multi-step estimate form builder
* Service-specific pricing ranges
* Lead capture and lead inbox
* Customer estimate emails
* Team notification emails and text alerts
* Customizable form button color and styling controls
* CSV lead export
* Shortcodes for embedding forms on pages
* Legacy shortcode compatibility for existing installs
* Free to use without a license key

= Shortcodes =

Use the current shortcode:

`[instant_estimate_form id="123"]`

Legacy shortcode remains supported for existing installs:

`[instant_quote_form id="123"]`

Replace `123` with the ID of your estimate form.

= Optional Done-For-You Setup =

The plugin is free to install and use. If you want help getting your first form built faster, Taggart Media Group offers optional done-for-you setup for service categories, pricing ranges, notifications, styling, testing, and launch.

Optional setup help is not required to use the plugin.

= Privacy / External Services =

Instant Estimate Builder stores form settings and leads on your WordPress site. The plugin does not silently send lead data to Taggart Media Group.

Optional integrations and support resources may connect to third-party services only when you configure or view those features:

* Klaviyo: If you enter a Klaviyo private API key and enable Klaviyo for a form, submitted lead details may be sent to Klaviyo, including name, email, phone, form name, and estimate range. Endpoint: `https://a.klaviyo.com/api/`. Klaviyo privacy policy: `https://www.klaviyo.com/legal/privacy`. Klaviyo terms: `https://www.klaviyo.com/legal/terms-of-service`.
* SMS/email-to-text notifications: If you configure text notification recipients, lead notification messages are sent through your WordPress email system to the selected carrier email-to-SMS gateway for each recipient. The data sent may include lead contact details and estimate information.
* Loom support videos: The plugin support page includes embedded tutorial videos hosted by Loom. Viewing the support page may load video content from `https://www.loom.com/`. Loom privacy policy: `https://www.loom.com/privacy-policy`. Loom terms: `https://www.loom.com/terms`.

These services are optional and are not required to create or use estimate forms.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/instant-estimate-builder` directory, or install the plugin through the WordPress Plugins screen.
2. Activate **Instant Estimate Builder** through the Plugins screen in WordPress.
3. Open **Instant Estimate Builder** in the WordPress admin menu.
4. Create your first estimate form.
5. Add the form shortcode to a page.
6. Submit a test lead and confirm notifications are working.

== Frequently Asked Questions ==

= Is the plugin free? =

Yes. Instant Estimate Builder is free to install and use. No license key is required.

= Do I need a paid account to use the plugin? =

No. Core plugin functionality works without an account or subscription.

= What businesses is this for? =

The plugin is designed for local service businesses that want to capture estimate-ready leads. Examples include roofing, windows, siding, HVAC, plumbing, landscaping, remodeling, pest control, and similar services.

= Does it create final binding quotes? =

No. The plugin is intended for estimate ranges and lead qualification. Businesses should review project details before providing a final quote, contract, or proposal.

= Can I embed multiple forms? =

Yes. Create multiple estimate forms and embed each one with its shortcode.

= Will my existing `[instant_quote_form]` shortcodes still work? =

Yes. The legacy shortcode remains supported for compatibility.

= Does the plugin collect data for Taggart Media Group? =

No. The plugin does not silently collect or send lead data to Taggart Media Group. Leads and settings are stored in your WordPress site.

= Can Taggart Media Group build my first form for me? =

Yes. Done-for-you setup is optional and available separately.

== Screenshots ==

1. Plugin dashboard with lead snapshot and builder shortcuts.
2. Estimate form builder.
3. Multi-step frontend estimate form.
4. Lead inbox.
5. Notification settings.

== Changelog ==

= 1.0.13 =
* Added WordPress.org plugin directory readme.
* Updated done-for-you setup CTA copy and link target.
* Added public landing page copy draft for launch review.
* Removed the GitHub release updater so WordPress.org installs use WordPress.org updates.
* Added external service disclosures and removed remote CDN/font dependencies for WordPress.org compliance.
* Removed the vendor tracking footer from customer estimate emails.

= 1.0.12 =
* Added packaged release ZIP support for cleaner WordPress plugin updates.

= 1.0.11 =
* Added branded plugin icon assets for WordPress update/details screens.
* Added update metadata for WordPress/PHP compatibility.
* Corrected the 2x plugin icon asset path.

= 1.0.10 =
* Security hardening for admin capabilities, exports, AJAX actions, settings sanitization, and preview access.

== Upgrade Notice ==

= 1.0.13 =
Prepares Instant Estimate Builder for public plugin directory distribution and updates the optional done-for-you setup CTA.
