<?php
error_log('✅ email-settings.php loaded');
if (function_exists('hgm_render_email_settings_page')) {
    error_log('✅ hgm_render_email_settings_page function EXISTS');
} else {
    error_log('❌ hgm_render_email_settings_page function NOT found');
}
function hgm_render_email_settings_page() {
    error_log('🔥 Email settings page callback fired!');
    $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'customize';

    echo '<div class="wrap">';
    echo '<h1>Email Settings</h1>';

    // Tabs
    echo '<h2 class="nav-tab-wrapper">';
    echo '<a href="?post_type=instant_quote_form&page=hgm-email-settings&tab=customize" class="nav-tab ' . ($active_tab === 'customize' ? 'nav-tab-active' : '') . '">Customize Estimate Email</a>';
    echo '<a href="?post_type=instant_quote_form&page=hgm-email-settings&tab=preview" class="nav-tab ' . ($active_tab === 'preview' ? 'nav-tab-active' : '') . '">Preview and Send a Test Email</a>';
    echo '</h2>';

    // Tab content
    if ($active_tab === 'customize') {
        echo '<form method="post" action="options.php">';
        settings_fields('hgm_email_settings');
        do_settings_sections('hgm-email-settings');
        submit_button('Save Email Settings');
        echo '</form>';
    } elseif ($active_tab === 'preview') {
        echo '<div style="text-align: center; margin: 40px 0;">';
        echo '<h2>Send Test Email</h2>';
        echo '<form method="post" style="display: inline-flex; align-items: center; gap: 10px;">';
        echo '<input type="email" name="hgm_test_email" placeholder="Enter your email" required class="regular-text">';
        echo '<button type="submit" class="button button-primary" name="hgm_send_test_email">Send Test Email</button>';
        echo '</form>';
        echo '</div>';
        echo '<hr>';
        echo hgm_get_estimate_email_preview_html();
    }

    echo '</div>';
}

function hgm_render_email_setting_field($args) {
    $key     = $args['key'];
    $options = get_option('hgm_email_settings');

    // Default values
    if ($key === 'phone_number') {
        $value = isset($options[$key]) && $options[$key] !== '' ? esc_attr($options[$key]) : '(800) 123-4567';
    } elseif ($key === 'phone_text') {
        $value = isset($options[$key]) && $options[$key] !== '' ? esc_attr($options[$key]) : 'Call Us:';
    } elseif ($key === 'button_text') {
        $value = isset($options[$key]) && $options[$key] !== '' ? esc_attr($options[$key]) : 'Book a Free In-Home Estimate';
    } elseif ($key === 'body_copy') {
        $value = isset($options[$key]) && $options[$key] !== '' 
            ? esc_textarea($options[$key]) 
            : "Thank you for completing our instant estimate form. Based on your answers, here's your estimated price range:";
    } else {
        $value = esc_attr($options[$key] ?? '');
    }
    if ($key === 'logo_url') {
        echo "<input type='text' id='hgm_logo_url' name='hgm_email_settings[$key]' value='$value' class='regular-text' />";
        echo " <button type='button' class='button' id='hgm_upload_logo_button'>Upload/Choose Image</button>";
    } elseif ($key === 'body_copy') {
        $default = 'Thank you for completing our instant estimate form. Based on your answers, here\'s your estimated price range:';
        $value = $value ?: $default;
        echo "<textarea name='hgm_email_settings[$key]' rows='4' class='regular-text'>$value</textarea>";
    } elseif ($key === 'disclaimer') {
        $default = '*This is a rough estimate. Pricing may vary depending on your specific home, brand preferences, ductwork, and available rebates in your area.';
        $value = $value ?: $default;
        echo "<textarea name='hgm_email_settings[$key]' rows='4' class='regular-text'>$value</textarea>";
    } elseif ($key === 'text_below_btn') {
        $default = 'If you have any questions or want to speak with an HVAC specialist, just reply to this email or call us at';
        $value = $value ?: $default;
        echo "<textarea name='hgm_email_settings[$key]' rows='2' class='regular-text'>$value</textarea>";
    } elseif ($key === 'button_text') {
        $default = 'Book a Free In-Home Estimate';
        $value = $value ?: $default;
        echo "<input type='text' name='hgm_email_settings[$key]' value='$value' class='regular-text'>";
    } elseif ($key === 'phone_text') {
        $default = '(800) 123-4567';
        $value = $value ?: $default;
        echo "<input type='text' name='hgm_email_settings[$key]' value='$value' class='regular-text'>";
    } elseif ($key === 'primary_color') {
        $default = '#013c55';
        $value = $value ?: $default;
        echo "<input type='text' name='hgm_email_settings[$key]' value='$value' class='hgm-color-field' />";
    } else {
        echo "<input type='text' name='hgm_email_settings[$key]' value='$value' class='regular-text'>";
    }
}
add_action('admin_init', function () {
    register_setting('hgm_email_settings', 'hgm_email_settings');

    add_settings_section(
        'hgm_email_section',
        'Customize Email Content',
        '__return_null',
        'hgm-email-settings'
    );

    $fields = [
        'logo_url'       => 'Logo URL',
        'phone_text'     => 'Phone Text',
        'phone_number'   => 'Phone Number',
        'button_text'    => 'Button Text',
        'button_link'    => 'Button Link <br>paste the link or use tel: for a phone number e.g. <br>tel:800-123-4567',
        'body_copy'      => 'Body Copy',
        'disclaimer'     => 'Disclaimer Text (below estimate)',
        'text_below_btn' => 'Text Below Button',
        'company_name'   => 'Company Name',
        'reply_to'       => 'Reply-To Email',
        'primary_color'  => 'Primary Color (#hex)',
    ];

    foreach ($fields as $key => $label) {
        add_settings_field(
            "hgm_email_settings[$key]",
            $label,
            'hgm_render_email_setting_field',
            'hgm-email-settings',
            'hgm_email_section',
            ['key' => $key, 'label' => $label]
        );
    }
});
// Preview Email Page (Admin)
function hgm_get_estimate_email_preview_html() {
    ob_start();

    // Sample fallback values
    $first_name    = 'John';
    $estimate_low  = '$7,500';
    $estimate_high = '$11,200';

    // Pull settings from DB
    $options = get_option('hgm_email_settings', []);
    $logo_url        = esc_url($options['logo_url'] ?? '');
    $phone_text      = sanitize_text_field($options['phone_text'] ?? 'Call Us:');
    $phone_link      = sanitize_text_field($options['phone_number'] ?? '');
    $button_text     = sanitize_text_field($options['button_text'] ?? 'Book a Free In-Home Estimate');
    $button_link     = esc_url($options['button_link'] ?? '');
    $body_copy       = wp_kses_post($options['body_copy'] ?? '');
    $disclaimer      = wp_kses_post($options['disclaimer'] ?? '');
    $text_below_btn  = wp_kses_post($options['text_below_btn'] ?? '');
    $company_name    = sanitize_text_field($options['company_name'] ?? '');
    $primary_color   = sanitize_hex_color($options['primary_color'] ?? '#013c55');

    include plugin_dir_path(__FILE__) . 'estimate-email-to-customer.php';

    return ob_get_clean();
}

add_action('admin_menu', function () {
    add_submenu_page(
        null, // hidden from menu
        'Estimate Email Preview',
        'Estimate Email Preview',
        'manage_options',
        'hgm-email-preview',
        function () {
            echo hgm_get_estimate_email_preview_html();
        }
    );
});
add_action('admin_init', function () {
    if (isset($_POST['hgm_send_test_email']) && isset($_POST['hgm_test_email'])) {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $to = sanitize_email($_POST['hgm_test_email']);
        $subject = '[Test] HVAC Estimate Email';

        ob_start();
        include plugin_dir_path(__FILE__) . 'estimate-email-to-customer.php';
        $message = ob_get_clean();

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'Reply-To: ' . sanitize_email(get_option('hgm_email_settings')['reply_to'] ?? get_bloginfo('admin_email')),
        ];

        wp_mail($to, $subject, $message, $headers);

        // Set transient to show admin notice
        set_transient('hgm_test_email_sent', $to, 30);
        // Redirect to avoid form resubmission
        wp_redirect(add_query_arg(['page' => 'hgm-email-settings', 'tab' => 'preview'], admin_url('admin.php')));
        exit;
    }
});

add_action('admin_notices', function () {
    $sent_to = get_transient('hgm_test_email_sent');
    if ($sent_to) {
        echo '<div class="notice notice-success is-dismissible"><p>Test email sent to ' . esc_html($sent_to) . '.</p></div>';
        delete_transient('hgm_test_email_sent');
    }
});