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
<div class="wrap">
    <h1>Integrations</h1>
    <?php
    // ======================
    // SHOW SETTINGS SUCCESS / ERROR MESSAGES
    // ======================
    settings_errors();
    ?>
    <form method="post" action="options.php">
        <?php
    // Output security fields
    settings_fields('hgm_integrations_settings');

    // Output settings sections + fields
    do_settings_sections('hgm-integrations');

    // Save button
    submit_button();
        ?>
    </form>
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