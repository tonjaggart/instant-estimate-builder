<?php
// Ensure media uploader is available
function hgm_enqueue_email_settings_media($hook) {
    if (strpos($hook, 'instant-estimate-email-settings') !== false || strpos($hook, 'hgm-email-settings') !== false) {
        wp_enqueue_media();
    }
}
add_action('admin_enqueue_scripts', 'hgm_enqueue_email_settings_media');
function hgm_render_email_settings_page() {
    $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'customize';
    $page_slug = 'instant-estimate-email-settings';

    echo '<div class="wrap hgm-dashboard ieb-email-settings-page">';

    echo '<section class="hgm-dashboard-hero ieb-email-settings-hero">';
    echo '<div class="hgm-dashboard-eyebrow">Email Settings</div>';
    echo '<h1>Estimate Email Settings</h1>';
    echo '<p class="hgm-dashboard-subtitle">Customize the branded estimate email that is automatically sent to leads after they complete an instant estimate form.</p>';
    echo '<div class="hgm-dashboard-actions">';
    echo '<a href="' . esc_url(admin_url('admin.php?page=' . IEB_ADMIN_MENU_SLUG)) . '" class="button hgm-button-secondary">Back To Dashboard</a>';
    echo '<a href="' . esc_url(admin_url('admin.php?page=instant-estimate-forms')) . '" class="button hgm-button-secondary">View Estimate Forms</a>';
    echo '</div>';
    echo '</section>';

    echo '<div class="ieb-settings-tabs">';
    echo '<a href="' . esc_url(admin_url('admin.php?page=' . $page_slug . '&tab=customize')) . '" class="ieb-settings-tab ' . ($active_tab === 'customize' ? 'is-active' : '') . '">Customize Estimate Email</a>';
    echo '<a href="' . esc_url(admin_url('admin.php?page=' . $page_slug . '&tab=preview')) . '" class="ieb-settings-tab ' . ($active_tab === 'preview' ? 'is-active' : '') . '">Preview and Send a Test Email</a>';
    echo '</div>';

    if ($active_tab === 'customize') {
        echo '<div class="hgm-dashboard-card ieb-email-settings-card">';
        echo '<div class="hgm-card-label">Email Content</div>';
        echo '<h2>Customize Email Content</h2>';
        echo '<p>Update the logo, CTA, company details, color, and message copy that appear in the customer estimate email.</p>';
        echo '<form method="post" action="options.php" class="ieb-email-settings-form">';
        settings_fields('hgm_email_settings');
        do_settings_sections('hgm-email-settings');
        submit_button('Save Email Settings', 'primary hgm-button-primary ieb-email-save-button', 'submit', true);
        echo '</form>';
        echo '</div>';
    } elseif ($active_tab === 'preview') {
        echo '<div class="hgm-dashboard-card ieb-email-settings-card ieb-email-preview-card">';
        echo '<div class="hgm-card-label">Preview</div>';
        echo '<h2>Preview and Send a Test Email</h2>';
        echo '<div class="ieb-email-preview-legacy">';
        echo '<div style="text-align: center; margin: 40px 0;">';
        echo '<h2>Send Test Email</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display: inline-flex; align-items: center; gap: 10px;">';
        echo '<input type="hidden" name="action" value="hgm_send_test_email">';
        wp_nonce_field('hgm_send_test_email_action', 'hgm_send_test_email_nonce');
        echo '<input type="email" name="hgm_test_email" placeholder="Enter your email" required class="regular-text">';
        echo '<button type="submit" class="button button-primary" name="hgm_send_test_email">Send Test Email</button>';
        echo '</form>';
        echo '</div>';
        echo '<hr>';
        echo '<div style="margin-top:40px;">';
        try {
            echo hgm_get_customer_email_html();
        } catch (Throwable $e) {
            echo '<div style="color:red; font-weight:bold;">Preview error: ' . esc_html($e->getMessage()) . '</div>';
        }
        echo '</div>';
        echo '</div>';
        echo '</div>';
    }

    echo '</div>';
}

function hgm_render_email_setting_field($args) {
    $key     = $args['key'];
    $options = get_option('hgm_email_settings', []);

    // Define default values
    $defaults = [
        'phone_number'    => '(800) 123-4567',
        'phone_text'      => 'Call Us:',
        'button_text'     => 'Book a Free In-Home Estimate',
        'button_link'     => '',
        'body_copy'       => "Thank you for completing our HVAC estimate form. Based on your answers, here's your estimated price range:",
        'disclaimer'      => '*This is a rough estimate. Pricing may vary depending on your specific home, brand preferences, ductwork, and available rebates in your area.',
        'text_below_btn'  => 'If you have any questions or want to speak with an HVAC specialist, just reply to this email or call us at',
        'company_name'    => '',
        'reply_to'        => '',
        'primary_color'   => '#013c55',
        'logo_url'        => '',
    ];

    $value = $options[$key] ?? $defaults[$key] ?? '';

    // Output the field
    switch ($key) {
        case 'logo_url':
            echo "<input type='text' id='hgm_logo_url' name='hgm_email_settings[$key]' value='" . esc_url($value) . "' class='regular-text ieb-email-logo-url' />";
            echo " <button type='button' class='button hgm-button-secondary ieb-email-upload-button' id='hgm_upload_logo_button'>Upload/Choose Image</button>";
            break;

        case 'body_copy':
        case 'disclaimer':
        case 'text_below_btn':
            $rows = $key === 'text_below_btn' ? 2 : 4;
            echo "<textarea name='hgm_email_settings[$key]' rows='$rows' class='regular-text ieb-email-textarea'>" . esc_textarea($value) . "</textarea>";
            break;

        case 'primary_color':
            echo "<input type='text' name='hgm_email_settings[$key]' value='" . esc_attr($value) . "' class='hgm-color-field ieb-email-color-field' />";
            break;

        default:
            echo "<input type='text' name='hgm_email_settings[$key]' value='" . esc_attr($value) . "' class='regular-text ieb-email-input'>";
            break;
    }
}

add_action('admin_init', function () {
    register_setting('hgm_email_settings', 'hgm_email_settings', [
        'sanitize_callback' => 'hgm_merge_email_settings',
    ]);

    add_settings_section(
        'hgm_email_section',
        '',
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

    // Set up sample fallback values
    $first_name    = 'John';
    $estimate_low  = '$7,500';
    $estimate_high = '$11,200';

    // Pull admin settings
    $options = get_option('hgm_email_settings', []);
    $logo_url        = isset($options['logo_url']) ? esc_url($options['logo_url']) : '';
    $phone_text      = $options['phone_text'] ?? 'Call Us:';
    $phone_number     = isset($options['phone_number']) ? sanitize_text_field($options['phone_number']) : '';
    $sanitized_phone  = preg_replace('/[^0-9]/', '', $phone_number);
    $button_text     = $options['button_text'] ?? 'Book a Free In-Home Estimate';
    $button_link     = isset($options['button_link']) ? esc_url($options['button_link']) : '';
    $body_copy       = $options['body_copy'] ?? 'This is a preview of the email content.';
    $disclaimer      = $options['disclaimer'] ?? '';
    $text_below_btn  = $options['text_below_btn'] ?? '';
    $company_name    = $options['company_name'] ?? 'Your Company';
    $primary_color   = sanitize_hex_color($options['primary_color'] ?? '#013c55');
    $reply_to_email  = $options['reply_to_email'] ?? 'jon@taggartmediagroup.com';

    // Begin output buffering
    ob_start();

    // Directly include the email template
    include plugin_dir_path(__FILE__) . 'email-template-customer.php';

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
            echo hgm_get_customer_email_html();
        }
    );
});

require_once plugin_dir_path(__FILE__) . 'email-template-customer.php';
add_action('admin_post_hgm_send_test_email', function () {

    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }

    if (!isset($_POST['hgm_test_email'])) {
        wp_die('Missing test email address');
    }

    $to = sanitize_email($_POST['hgm_test_email']);
    $subject = '[Test] HVAC Estimate Email';

    $email_settings = get_option('hgm_email_settings', []);

    $first_name     = 'John'; // fallback test name
    $estimate_low   = '$7,500';
    $estimate_high  = '$11,200';
    $logo_url       = esc_url($email_settings['logo_url'] ?? '');
    $phone_text     = sanitize_text_field($email_settings['phone_text'] ?? 'Call Us:');
    $phone_number   = sanitize_text_field($email_settings['phone_number'] ?? '');
    $button_text    = sanitize_text_field($email_settings['button_text'] ?? 'Book a Free In-Home Estimate');
    $button_link    = esc_url($email_settings['button_link'] ?? '');
    $body_copy      = wp_kses_post($email_settings['body_copy'] ?? '');
    $disclaimer     = wp_kses_post($email_settings['disclaimer'] ?? '');
    $text_below_btn = wp_kses_post($email_settings['text_below_btn'] ?? '');
    $company_name   = sanitize_text_field($email_settings['company_name'] ?? '');
    $reply_to       = sanitize_email($email_settings['reply_to'] ?? get_bloginfo('admin_email'));
    $primary_color  = sanitize_hex_color($email_settings['primary_color'] ?? '#013c55');

    $message = hgm_get_customer_email_html(); // this triggers preview mode

    // Add this:
    error_log('🧪 Rendered message length: ' . strlen($message));
    error_log('🧪 Raw message preview: ' . substr($message, 0, 200));

    $headers = [
        'Content-Type: text/html; charset=UTF-8',
        'Reply-To: ' . $reply_to,
    ];


    wp_mail($to, $subject, $message, $headers);

    set_transient('hgm_test_email_sent', $to, 30);
    wp_redirect(admin_url('admin.php?page=instant-estimate-email-settings&tab=preview'));
    exit;
});

add_action('admin_notices', function () {
    $sent_to = get_transient('hgm_test_email_sent');
    if ($sent_to) {
        echo '<div class="notice notice-success is-dismissible"><p>Test email sent to ' . esc_html($sent_to) . '.</p></div>';
        delete_transient('hgm_test_email_sent');
    }
});

add_action('admin_enqueue_scripts', function ($hook) {
    if (strpos($hook, 'instant-estimate-email-settings') !== false || strpos($hook, 'hgm-email-settings') !== false) {
        wp_enqueue_media();
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_style('hgm-dashboard-css', plugin_dir_url(__FILE__) . '../assets/dashboard.css', [], filemtime(plugin_dir_path(__FILE__) . '../assets/dashboard.css'));
        wp_enqueue_style('hgm-admin-css', plugin_dir_url(__FILE__) . '../assets/admin.css', [], filemtime(plugin_dir_path(__FILE__) . '../assets/admin.css'));
        wp_enqueue_script('hgm-admin-media', plugin_dir_url(__FILE__) . '../assets/admin.js', ['jquery', 'wp-color-picker'], null, true);
    }
});
require_once plugin_dir_path(__FILE__) . 'email-template-customer.php';

function hgm_merge_email_settings($new) {
    $existing = get_option('hgm_email_settings', []);
    return array_merge($existing, $new);
}

function hgm_render_customer_email_template($args = []) {
    extract($args); // turns array keys into variables
    ob_start();
    include plugin_dir_path(__FILE__) . 'email-template-customer.php';
    return ob_get_clean();
}