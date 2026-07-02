<?php
function hgm_get_license_status_secure() {
    $status     = get_option('hgm_license_status_cache', 'inactive');
    $last_check = (int) get_option('hgm_license_checked_at', 0);
    $now        = time();

    // Check if cache expired (24 hours)
    if ($now - $last_check > DAY_IN_SECONDS) {
        $email = get_option('hgm_license_email', '');
        $key   = get_option('hgm_license_key', '');

        if (empty($email) || empty($key)) {
            update_option('hgm_license_status_cache', 'inactive');
            update_option('hgm_license_checked_at', $now);
            return 'inactive';
        }

        // Call your server API
        $api_url = add_query_arg([
            'wc-api'      => 'software-api',
            'request'     => 'check',
            'email'       => $email,
            'licence_key' => $key,
            'product_id'  => 'HVAC-Instant-Quote-Generator', // Adjust product ID
            'instance'    => home_url()
        ], 'https://hvacgrowthmachine.com/');

        $response = wp_remote_get($api_url, [
            'timeout'   => 20,
            'sslverify' => true
        ]);

        if (!is_wp_error($response)) {
            $data = json_decode(wp_remote_retrieve_body($response), true);

            if (!empty($data['status'])) {
                $status = sanitize_text_field($data['status']);
                update_option('hgm_license_status_cache', $status);
                update_option('hgm_license_checked_at', $now);
            }
        }
    }

    return $status;
}
function render_instant_quote_form_shortcode($atts) {

    // ✅ Ensure scripts load wherever shortcode is used (ACF, templates, etc.)
    if (!wp_script_is('hgm-multi-step', 'enqueued')) {

        wp_enqueue_style(
            'hgm-frontend-css',
            HGM_PLUGIN_URL . 'assets/admin.css',
            [],
            filemtime(HGM_PLUGIN_PATH . 'assets/admin.css')
        );

        wp_enqueue_script(
            'hgm-multi-step',
            HGM_PLUGIN_URL . 'assets/multi-step-form.js',
            ['jquery'],
            filemtime(HGM_PLUGIN_PATH . 'assets/multi-step-form.js'),
            true
        );

        wp_localize_script('hgm-multi-step', 'hgm_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('hgm_nonce'),
        ]);
    }
    $status = hgm_get_license_status_secure();

    // 1) If this license is already claimed by another root domain:
    if ($status === 'domain_taken') {
        if (current_user_can('manage_options')) {
            return '<div class="hgm-license-error" style="border:1px solid red;padding:15px;background:#fff3f3;color:#d63638;">
                <strong>License Domain Mismatch:</strong> This license is already claimed by another domain, so the form cannot render on this site.
                Manage it under <a href="' . esc_url(admin_url('admin.php?page=hgm-license-settings')) . '" style="color:#d63638;font-weight:bold;text-decoration:underline;">License</a>.
            </div>';
        }
        return ''; // visitors see nothing
    }

    // 2) For any status that is not active (inactive/invalid/expired), keep your current public message:
    if ($status !== 'active') {
        return '<div class="hgm-license-error" style="border:1px solid red;padding:15px;background:#fff3f3;color:#d63638;">
            <strong>License Required:</strong> The HVAC Instant Quote Generator is not activated on this site.
            Please enter your license key in the WordPress admin under <a href="' . esc_url(admin_url('admin.php?page=hgm-license-settings')) . '" style="color:#d63638;font-weight:bold;text-decoration:underline;">License</a> to enable this form.
        </div>';
    }

    // 3) Normal rendering
    $atts = shortcode_atts(['id' => 0], $atts, 'instant_quote_form');
    $form_id   = $atts['id'] ? absint($atts['id']) : get_the_ID();
    $form_data = get_post_meta($form_id, '_hgm_form_data', true);

    if (empty($form_data) || !is_array($form_data)) {
        return '<p>No quote form data found for this ID.</p>';
    }

    ob_start();
    echo '<!-- ✅ multi-step-form.php loaded -->';
    include plugin_dir_path(__FILE__) . 'multi-step-form.php';

    return ob_get_clean();
}
add_shortcode('instant_quote_form', 'render_instant_quote_form_shortcode');