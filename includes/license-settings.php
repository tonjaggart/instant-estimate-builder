<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Render updates/setup page.
 *
 * Legacy menu slug and callback name are kept for existing installs, but the
 * plugin is now free and no longer asks users for a license key.
 */
function hgm_render_license_settings_page() {
?>
<div class="wrap hgm-updates-page">
    <h1>Instant Estimate Builder Updates</h1>

    <div class="notice notice-success">
        <p><strong>Instant Estimate Builder is free to use.</strong> No license key is required.</p>
    </div>

    <div class="card" style="max-width: 760px; padding: 24px; border-radius: 14px;">
        <h2 style="margin-top: 0;">Need help getting your form launched?</h2>
        <p>Taggart Media Group can configure your services, estimate ranges, notifications, styling, and test the full form for you.</p>
        <p><strong>One-time setup: $299</strong></p>
        <p>
            <a class="button button-primary" href="https://taggartmediagroup.com/instant-estimate-builder/?utm_source=instant_estimate_builder&utm_medium=plugin&utm_campaign=wporg_launch&utm_content=updates_setup_help_cta" target="_blank" rel="noopener">Get Setup Help</a>
        </p>
    </div>
</div>
<?php
}
