<?php
// ======================
// SCOPED ADMIN NOTICE (ONLY FOR NOTIFICATIONS PAGE)
// ======================
add_action('admin_notices', function () {

    // Only run on Notifications settings page
    if (!isset($_GET['page']) || !in_array($_GET['page'], ['instant-estimate-notifications', 'hgm-notifications-settings'], true)) {
        return;
    }

    // Only show message after settings are saved
    if (isset($_GET['settings-updated']) && $_GET['settings-updated'] === 'true') {
        echo '<div class="notice notice-success is-dismissible"><p>Notification settings saved.</p></div>';
    }

});

add_action('admin_enqueue_scripts', function ($hook) {
    if (strpos($hook, 'instant-estimate-notifications') !== false || strpos($hook, 'hgm-notifications-settings') !== false) {
        wp_enqueue_style('hgm-dashboard-css', plugin_dir_url(__FILE__) . '../assets/dashboard.css', [], filemtime(plugin_dir_path(__FILE__) . '../assets/dashboard.css'));
        wp_enqueue_style('hgm-admin-css', plugin_dir_url(__FILE__) . '../assets/admin.css', [], filemtime(plugin_dir_path(__FILE__) . '../assets/admin.css'));
        wp_enqueue_script('hgm-admin-js', plugin_dir_url(__FILE__) . '../assets/admin.js', ['jquery'], null, true);
    }
});

function hgm_render_notifications_settings_page() {
    $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'email';
    $page_slug = 'instant-estimate-notifications';

    echo '<div class="wrap hgm-dashboard ieb-notifications-page">';

    echo '<section class="hgm-dashboard-hero ieb-notifications-hero">';
    echo '<div class="hgm-dashboard-eyebrow">Lead Notifications</div>';
    echo '<h1>Instant Estimate Notifications</h1>';
    echo '<p class="hgm-dashboard-subtitle">Set up the email and text alerts sent to your team when a new instant estimate lead comes in.</p>';
    echo '<div class="hgm-dashboard-actions">';
    echo '<a href="' . esc_url(admin_url('admin.php?page=' . IEB_ADMIN_MENU_SLUG)) . '" class="button hgm-button-secondary">Back To Dashboard</a>';
    echo '<a href="' . esc_url(admin_url('admin.php?page=instant-estimate-leads')) . '" class="button hgm-button-secondary">View Leads</a>';
    echo '</div>';
    echo '</section>';

    echo '<div class="ieb-settings-tabs ieb-notifications-tabs">';
    echo '<a href="' . esc_url(admin_url('admin.php?page=' . $page_slug . '&tab=email')) . '" class="ieb-settings-tab ' . ($active_tab === 'email' ? 'is-active' : '') . '">Email Notifications</a>';
    echo '<a href="' . esc_url(admin_url('admin.php?page=' . $page_slug . '&tab=text')) . '" class="ieb-settings-tab ' . ($active_tab === 'text' ? 'is-active' : '') . '">Text Notifications</a>';
    echo '</div>';

    $options = get_option('hgm_email_settings', []);
    if ($active_tab === 'email') {
        $sales_emails = $options['sales_team_emails'] ?? '';

        echo '<div class="hgm-dashboard-card ieb-notifications-card">';
        echo '<div class="hgm-card-label">Email Alerts</div>';
        echo '<h2>Email Notifications For Sales Team</h2>';
        echo '<p>Add the sales/admin email addresses that should receive a notification when a new estimate lead is submitted.</p>';
        echo '<form method="post" action="options.php" class="ieb-notifications-form">';
        settings_fields('hgm_email_settings');
        echo '<div class="ieb-notification-field-row">';
        echo '<label for="hgm_email_settings_sales_team_emails">Sales Team Emails</label>';
        echo '<div class="ieb-notification-field-control">';
        echo '<textarea id="hgm_email_settings_sales_team_emails" name="hgm_email_settings[sales_team_emails]" rows="5" class="large-text code ieb-notification-textarea" placeholder="jon@company.com,support@company.com">';
        echo esc_textarea($sales_emails);
        echo '</textarea>';
        echo '<p class="description ieb-email-field-hint">Separate multiple emails with commas. Example: jon@company.com,support@company.com</p>';
        echo '</div>';
        echo '</div>';
        submit_button('Save Email Notifications', 'primary hgm-button-primary ieb-notification-save-button', 'submit', true);
        echo '</form>';
        echo '</div>';
    }

    if ($active_tab === 'text') {
        // Keep the existing staging screenshot review route visual-only.
        $review_sms = isset($_GET['ieb_sms_preview']) && $_GET['ieb_sms_preview'] === '1' && current_user_can(defined('IEB_SENSITIVE_CAPABILITY') ? IEB_SENSITIVE_CAPABILITY : 'manage_options');
        if ($review_sms) {
            echo '<div class="hgm-dashboard-card ieb-notifications-card" id="ieb-planned-sms">';
            echo '<div class="hgm-card-label">Text alerts</div>';
            echo '<h2>Instant Estimate Builder SMS Alerts</h2>';
            echo '<p>Receive a text alert when a new estimate request is submitted. Alerts may include the prospective customer’s name, phone number, and requested service.</p>';
            echo '<div class="ieb-notifications-form">';
            echo '<p><label for="ieb-sms-review-phone"><strong>Your mobile number</strong></label><br><input id="ieb-sms-review-phone" type="tel" inputmode="numeric" placeholder="10-digit mobile number" class="regular-text" /></p>';
            echo '<p><label><input type="checkbox" /> I agree to receive recurring Instant Estimate Builder estimate-request text alerts from Taggart Media Group LLC at the mobile number I provide. Alerts may include lead contact details. Message frequency varies. Message and data rates may apply. Reply HELP for help or STOP to unsubscribe.</label></p>';
            echo '<p><a href="https://taggartmediagroup.com/instant-estimate-builder-sms-alert-terms/" target="_blank" rel="noopener noreferrer">SMS Alert Terms</a> &nbsp;|&nbsp; <a href="https://taggartmediagroup.com/privacy-policy/" target="_blank" rel="noopener noreferrer">Privacy Policy</a></p>';
            echo '<p><button type="button" class="button button-primary">Save Text Alerts</button></p>';
            echo '</div></div>';
        } else {
            ieb_sms_render_settings();
        }
    }

    echo '</div>';
}

function ieb_sanitize_notification_settings($input) {
    $input = is_array($input) ? $input : [];
    $allowed_carriers = ['verizon', 'att', 'tmobile', 'sprint', 'uscellular'];
    $recipients = [];

    if (!empty($input['sms_recipients']) && is_array($input['sms_recipients'])) {
        foreach ($input['sms_recipients'] as $recipient) {
            if (count($recipients) >= 5) {
                break;
            }

            $phone = preg_replace('/\D+/', '', $recipient['phone'] ?? '');
            $carrier = sanitize_key($recipient['carrier'] ?? '');

            if (strlen($phone) === 10 && in_array($carrier, $allowed_carriers, true)) {
                $recipients[] = [
                    'phone' => $phone,
                    'carrier' => $carrier,
                ];
            }
        }
    }

    return ['sms_recipients' => $recipients];
}

add_action('admin_init', function () {
    register_setting('hgm_notification_settings', 'hgm_notification_settings', [
        'type' => 'array',
        'sanitize_callback' => 'ieb_sanitize_notification_settings',
        'default' => [],
    ]);
});

add_filter('option_page_capability_hgm_notification_settings', function () {
    return defined('IEB_SENSITIVE_CAPABILITY') ? IEB_SENSITIVE_CAPABILITY : 'manage_options';
});

add_filter('option_page_capability_hgm_email_settings', function ($capability) {
    $referer = wp_get_referer();

    if ($referer && strpos($referer, 'page=instant-estimate-notifications') !== false) {
        return defined('IEB_SENSITIVE_CAPABILITY') ? IEB_SENSITIVE_CAPABILITY : 'manage_options';
    }

    return $capability;
});
