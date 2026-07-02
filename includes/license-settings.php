<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Render License Settings Page
 */
function hgm_render_license_settings_page() {
    $license_key    = get_option('hgm_license_key', '');
    $license_email  = get_option('hgm_license_email', '');
    $public_key     = get_option('hgm_public_key', '');
    $license_status = get_option('hgm_license_status', 'inactive');
    $primary_domain = get_option('hgm_license_bound_domain', '');
?>
<div class="wrap">
    <h1>HVAC Instant Quote Generator - License</h1>

    <?php if ( $license_status === 'active' ): ?>
    <div class="notice notice-success"><p>✅ License is active and valid<?php
    if ($primary_domain) echo ' (Primary Domain: <code>' . esc_html($primary_domain) . '</code>)';
        ?>.</p></div>
    <?php elseif ( $license_status === 'expired' ): ?>
    <div class="notice notice-error"><p>❌ License has expired. Please renew to continue using the plugin.</p></div>
    <?php elseif ( $license_status === 'invalid' ): ?>
    <div class="notice notice-error"><p>❌ Invalid license key, email, or public key. Please check your details.</p></div>
    <?php elseif ( $license_status === 'domain_taken' ): ?>
    <div class="notice notice-error">
        <p>❌ This license is already claimed by
            <?php if ( $primary_domain ) : ?>
            <strong><code><?php echo esc_html( $primary_domain ); ?></code></strong>.
            <?php else : ?>
            another domain.
            <?php endif; ?>
            You can use localhost/dev or any subdomain of the primary domain, but not a different root domain.
        </p>
    </div>
    <?php endif; ?>

    <form method="post" action="">
        <?php wp_nonce_field('hgm_save_license', 'hgm_license_nonce'); ?>

        <table class="form-table">
            <tr>
                <th scope="row">License Email</th>
                <td>
                    <input type="email" name="hgm_license_email" value="<?php echo esc_attr($license_email); ?>" class="regular-text" required />
                    <p class="description">The email used for your purchase.</p>
                </td>
            </tr>
            <tr>
                <th scope="row">License Key</th>
                <td>
                    <input type="password" name="hgm_license_key" value="<?php echo esc_attr($license_key); ?>" class="regular-text" required autocomplete="off" />
                    <p class="description">Enter your license key from <a href="https://hvacgrowthmachine.com/my-account/hgm-licenses/" target="_blank">HVACGrowthMachine.com</a>.</p>
                </td>
            </tr>
            <tr>
                <th scope="row">Public Key</th>
                <td>
                    <input type="text" name="hgm_public_key" value="<?php echo esc_attr($public_key); ?>" class="regular-text" required />
                    <p class="description">Enter your public key from <a href="https://hvacgrowthmachine.com/my-account/hgm-licenses/" target="_blank">HVACGrowthMachine.com</a>.</p>
                </td>
            </tr>
        </table>

        <?php submit_button('Activate License'); ?>
    </form>
</div>
<?php
}

/**
 * Handle License Activation
 */
add_action('admin_init', function() {
    if (isset($_POST['hgm_license_nonce']) && wp_verify_nonce($_POST['hgm_license_nonce'], 'hgm_save_license')) {
        if (current_user_can('manage_options')) {

            $email      = sanitize_email($_POST['hgm_license_email']);
            $key        = sanitize_text_field($_POST['hgm_license_key']);
            $public_key = sanitize_text_field($_POST['hgm_public_key']); // ✅ Public key

            // Save all three values
            update_option('hgm_license_email', $email);
            update_option('hgm_license_key', $key);
            update_option('hgm_public_key', $public_key);

            // 🚀 Force clear cache so we always recheck immediately
            delete_option('hgm_license_status_cache');
            delete_option('hgm_license_checked_at');

            // 1) Try to ACTIVATE first (claims first non-dev domain; allows subdomains/dev)
            $activate_resp = wp_remote_post(
                'https://hvacgrowthmachine.com/wp-json/hgm-license/v1/activate',
                [
                    'timeout'   => 20,
                    'sslverify' => true,
                    'headers'   => ['Content-Type' => 'application/json'],
                    'body'      => wp_json_encode([
                        'email'       => $email,
                        'license_key' => $key,
                        'public_key'  => $public_key,
                        'instance'    => home_url('/'),
                    ]),
                ]
            );

            if (is_wp_error($activate_resp)) {
                update_option('hgm_license_status', 'invalid');
                update_option('hgm_license_status_cache', 'invalid');
                update_option('hgm_license_checked_at', time());
                return;
            }

            $http = (int) wp_remote_retrieve_response_code($activate_resp);
            $data = json_decode(wp_remote_retrieve_body($activate_resp), true);

            if ($http === 200 && !empty($data['status']) && $data['status'] === 'active') {
                update_option('hgm_license_status', 'active');
                update_option('hgm_license_status_cache', 'active');
                update_option('hgm_license_checked_at', time());

                if (!empty($data['primary_domain'])) {
                    update_option('hgm_license_bound_domain', sanitize_text_field($data['primary_domain']));
                }
                return;
            }

            if ($http === 403 && !empty($data['code']) && $data['code'] === 'domain_taken') {
                update_option('hgm_license_status', 'domain_taken');
                update_option('hgm_license_status_cache', 'domain_taken');
                update_option('hgm_license_checked_at', time());

                if (!empty($data['primary_domain'])) {
                    update_option('hgm_license_bound_domain', sanitize_text_field($data['primary_domain']));
                }
                return;
            }

            // 2) Fallback to /check to capture expired/invalid cases
            $response = wp_remote_get(add_query_arg([
                'email'       => $email,
                'license_key' => $key,
                'public_key'  => $public_key,
                'instance'    => home_url(),
            ], 'https://hvacgrowthmachine.com/wp-json/hgm-license/v1/check'), [
                'timeout'   => 20,
                'sslverify' => true
            ]);

            if (is_wp_error($response)) {
                update_option('hgm_license_status', 'invalid');
                update_option('hgm_license_status_cache', 'invalid');
                update_option('hgm_license_checked_at', time());
                return;
            }

            $data = json_decode(wp_remote_retrieve_body($response), true);

            if (!empty($data['status'])) {
                $status = sanitize_text_field($data['status']);
                update_option('hgm_license_status', $status);
                update_option('hgm_license_status_cache', $status);
                update_option('hgm_license_checked_at', time());
            } else {
                update_option('hgm_license_status', 'invalid');
                update_option('hgm_license_status_cache', 'invalid');
                update_option('hgm_license_checked_at', time());
            }
        }
    }
});