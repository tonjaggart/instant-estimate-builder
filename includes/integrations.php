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
    $has_api_key = !empty($api_key);
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
                        type="password"
                        id="hgm_klaviyo_api_key"
                        name="hgm_klaviyo_api_key"
                        value=""
                        class="regular-text ieb-integrations-input"
                        autocomplete="new-password"
                        placeholder="<?php echo $has_api_key ? esc_attr('Key saved — enter a new key to replace it') : esc_attr('Enter your Klaviyo Private API Key'); ?>"
                    />
                    <p class="description">Used server-side to sync estimate leads with Klaviyo. Saved keys are hidden after saving. Leave this blank to keep the current key.</p>
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
        'hgm_klaviyo_api_key',
        [
            'type' => 'string',
            'sanitize_callback' => 'ieb_sanitize_klaviyo_api_key',
        ]
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
            $has_value = !empty($value);
?>
<input
       type="password"
       name="hgm_klaviyo_api_key"
       value=""
       autocomplete="new-password"
       style="width:400px;"
       placeholder="<?php echo $has_value ? esc_attr('Key saved — enter a new key to replace it') : esc_attr('Enter your Klaviyo Private API Key'); ?>"
       />
<?php
        },
        'hgm-integrations',
        'hgm_klaviyo_section'
    );

});

function ieb_sanitize_klaviyo_api_key($value) {
    $value = is_string($value) ? trim(wp_unslash($value)) : '';

    if ($value === '') {
        return get_option('hgm_klaviyo_api_key', '');
    }

    return sanitize_text_field($value);
}

add_filter('option_page_capability_hgm_integrations_settings', function () {
    return defined('IEB_SENSITIVE_CAPABILITY') ? IEB_SENSITIVE_CAPABILITY : 'manage_options';
});
