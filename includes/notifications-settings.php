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
        $notification_options = get_option('hgm_notification_settings', []);
        $saved_recipients = $notification_options['sms_recipients'] ?? [];

        $carriers = [
            'verizon'    => 'Verizon',
            'att'        => 'AT&T',
            'tmobile'    => 'T-Mobile',
            'sprint'     => 'Sprint',
            'uscellular' => 'US Cellular',
        ];

        echo '<div class="hgm-dashboard-card ieb-notifications-card">';
        echo '<div class="hgm-card-label">Text Alerts</div>';
        echo '<h2>Text Notifications For New Estimate Leads</h2>';
        echo '<p>Add the phone numbers that should receive a text alert when a new estimate lead is submitted.</p>';
        echo '<form method="post" action="options.php" class="ieb-notifications-form">';
        settings_fields('hgm_notification_settings');
        echo '<div class="ieb-notification-field-row">';
        echo '<label>Recipient Phone Numbers</label>';
        echo '<div class="ieb-notification-field-control">';
        echo '<div id="sms-recipient-list" class="ieb-sms-recipient-list">';

        $has_recipients = !empty($saved_recipients);
        $recipients_to_render = $has_recipients ? $saved_recipients : [['phone' => '', 'carrier' => '']];

        foreach ($recipients_to_render as $index => $recipient) {
            $phone = esc_attr($recipient['phone'] ?? '');
            $carrier = esc_attr($recipient['carrier'] ?? '');

            echo '<div class="sms-recipient-row ieb-sms-recipient-row">';
            echo '<input type="text" name="hgm_notification_settings[sms_recipients]['.$index.'][phone]" value="'.$phone.'" placeholder="Phone Number" class="regular-text ieb-notification-input" />';
            echo '<select name="hgm_notification_settings[sms_recipients]['.$index.'][carrier]" class="ieb-notification-select">';
            foreach ($carriers as $value => $label) {
                $selected = ($carrier === $value) ? 'selected' : '';
                echo "<option value=\"$value\" $selected>$label</option>";
            }
            echo '</select>';
            echo '<a href="#" class="remove-recipient ieb-remove-recipient">Remove</a>';
            echo '</div>';
        }

        echo '</div>';
        echo '<button type="button" class="button hgm-button-secondary ieb-add-recipient-button" id="add-sms-recipient">Add Phone Number</button>';
        echo '<p class="description ieb-email-field-hint">Max 5 numbers. Format: 5551234567</p>';
        echo '</div>';
        echo '</div>';
        submit_button('Save Text Notifications', 'primary hgm-button-primary ieb-notification-save-button', 'submit', true);
        echo '</form>';
        echo '</div>';
    }

    echo '</div>';
}

add_action('admin_init', function () {
    register_setting('hgm_notification_settings', 'hgm_notification_settings');
});

add_filter('option_page_capability_hgm_notification_settings', function () {
    return 'edit_posts';
});

add_filter('option_page_capability_hgm_email_settings', function ($capability) {
    $referer = wp_get_referer();

    if ($referer && strpos($referer, 'page=instant-estimate-notifications') !== false) {
        return 'edit_posts';
    }

    return $capability;
});
