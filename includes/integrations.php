<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit();
}

/**
 * Render Integrations Page
 */
function hgm_render_integrations_page() {
?>
<div class="wrap hgm-dashboard ieb-integrations-page">
    <section class="hgm-dashboard-hero ieb-integrations-hero">
        <div class="hgm-dashboard-eyebrow">Integrations</div>
        <h1>Instant Estimate Integrations</h1>
        <p class="hgm-dashboard-subtitle">Connect Klaviyo so new estimate leads can flow into your email marketing lists.</p>
        <div class="hgm-dashboard-actions">
            <a href="<?php echo esc_url(admin_url('admin.php?page=' . IEB_ADMIN_MENU_SLUG)); ?>" class="button hgm-button-secondary">Back To Dashboard</a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=instant-estimate-forms')); ?>" class="button hgm-button-secondary">Manage Estimate Forms</a>
        </div>
    </section>
    <?php
    // ======================
    // SHOW SETTINGS SUCCESS / ERROR MESSAGES
    // ======================
    settings_errors();

    $api_key = get_option('hgm_klaviyo_api_key');
    ?>
    <div class="hgm-dashboard-card ieb-integrations-card">
        <div class="hgm-card-label">Klaviyo</div>
        <h2>Connect Klaviyo</h2>
        <p>Enter your Klaviyo Private API Key once, then choose which estimate forms should send leads into Klaviyo.</p>

        <form method="post" action="options.php" class="ieb-integrations-form">
            <?php settings_fields('hgm_integrations_settings'); ?>
            <div class="ieb-integrations-field-row">
                <label for="hgm_klaviyo_api_key">Private API Key</label>
                <div class="ieb-integrations-field-control">
                    <input
                        type="text"
                        id="hgm_klaviyo_api_key"
                        name="hgm_klaviyo_api_key"
                        value="<?php echo esc_attr($api_key); ?>"
                        class="regular-text ieb-integrations-input"
                        placeholder="Enter your Klaviyo Private API Key"
                    />
                    <p class="description">Used server-side to sync estimate leads with Klaviyo. You can update or remove it anytime.</p>
                </div>
            </div>
            <button type="submit" name="submit" id="submit" class="button button-primary hgm-button-primary">Save Klaviyo Settings</button>
        </form>
    </div>
</div>
<?php
}
/**
 * Register Klaviyo Integration Settings
 */
add_action('admin_init', function () {

    // Register API key setting
    register_setting(
        'hgm_integrations_settings',
        'hgm_klaviyo_api_key'
    );

    // Add Klaviyo section
    add_settings_section(
        'hgm_klaviyo_section',
        'Klaviyo',
        function () {
            echo '<p>Connect your Klaviyo account to send leads into your lists.</p>';
        },
        'hgm-integrations'
    );

    // Add API Key field
    add_settings_field(
        'hgm_klaviyo_api_key',
        'Private API Key',
        function () {
            $value = get_option('hgm_klaviyo_api_key');
?>
<input
       type="text"
       name="hgm_klaviyo_api_key"
       value="<?php echo esc_attr($value); ?>"
       style="width:400px;"
       placeholder="Enter your Klaviyo Private API Key"
       />
<?php
        },
        'hgm-integrations',
        'hgm_klaviyo_section'
    );

});

add_filter('option_page_capability_hgm_integrations_settings', function () {
    return 'edit_posts';
});
