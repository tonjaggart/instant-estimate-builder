<?php
// ======================
// SCOPED ADMIN NOTICE (ONLY FOR NOTIFICATIONS PAGE)
// ======================
add_action('admin_notices', function () {

    // Only run on Notifications settings page
    if (!isset($_GET['page']) || $_GET['page'] !== 'hgm-notifications-settings') {
        return;
    }

    // Only show message after settings are saved
    if (isset($_GET['settings-updated']) && $_GET['settings-updated'] === 'true') {
        echo '<div class="notice notice-success is-dismissible"><p>Text notification settings saved.</p></div>';
    }

});

add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook === 'toplevel_page_hgm-notifications-settings' || strpos($hook, 'hgm-notifications-settings') !== false) {
        wp_enqueue_script('hgm-admin-js', plugin_dir_url(__FILE__) . '../assets/admin.js', ['jquery'], null, true);
    }
});

function hgm_render_notifications_settings_page() {
    
    $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'email';

    echo '<div class="wrap">';
    echo '<h1>Notifications Settings</h1>';

    // Tabs
    echo '<h2 class="nav-tab-wrapper">';
    echo '<a href="' . admin_url('admin.php?page=hgm-notifications-settings&tab=email') . '" class="nav-tab ' . ($active_tab === 'email' ? 'nav-tab-active' : '') . '">Email Notifications for Sales Team</a>';
    echo '<a href="' . admin_url('admin.php?page=hgm-notifications-settings&tab=text') . '" class="nav-tab ' . ($active_tab === 'text' ? 'nav-tab-active' : '') . '">Text Notification Settings</a>';
    echo '</h2>';

    $options = get_option('hgm_email_settings', []);
    if ($active_tab === 'email') {
        $sales_emails = $options['sales_team_emails'] ?? '';

        echo '<form method="post" action="options.php">';
        settings_fields('hgm_email_settings');
        echo '<table class="form-table">';
        echo '<tr>';
        echo '<th scope="row"><label for="hgm_email_settings_sales_team_emails">Sales Team Emails</label></th>';
        echo '<td>';
        echo '<textarea id="hgm_email_settings_sales_team_emails" name="hgm_email_settings[sales_team_emails]" rows="5" class="large-text code" placeholder="Separate multiple emails with commas (no spaces).">';
        echo esc_textarea($sales_emails);
        echo '</textarea>';
        echo '<p class="description">Separate multiple emails with commas (no spaces).</p>';
        echo '</td>';
        echo '</tr>';
        echo '</table>';
        submit_button('Save Sales Team Emails');
        echo '</form>';
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
        
        echo '<h2>Send Text Notifications When a New Estimate is Created</h2>';
        echo '<form method="post" action="options.php">';
        settings_fields('hgm_notification_settings');
        echo '<table class="form-table">';

        echo '<tr>';
        echo '<th scope="row"><label>Recipient Phone Numbers</label></th>';
        echo '<td>';
        echo '<div id="sms-recipient-list">';

        $has_recipients = !empty($saved_recipients);
        $recipients_to_render = $has_recipients ? $saved_recipients : [['phone' => '', 'carrier' => '']];

        foreach ($recipients_to_render as $index => $recipient) {
            $phone = esc_attr($recipient['phone'] ?? '');
            $carrier = esc_attr($recipient['carrier'] ?? '');

            echo '<div class="sms-recipient-row" style="margin-bottom: 10px;">';
            echo '<input type="text" name="hgm_notification_settings[sms_recipients]['.$index.'][phone]" value="'.$phone.'" placeholder="Phone Number" class="regular-text" />';
            echo '<select name="hgm_notification_settings[sms_recipients]['.$index.'][carrier]">';
            foreach ($carriers as $value => $label) {
                $selected = ($carrier === $value) ? 'selected' : '';
                echo "<option value=\"$value\" $selected>$label</option>";
            }
            echo '</select>';
            echo ' <a href="#" class="remove-recipient">Remove</a>';
            echo '</div>';
        }

        echo '</div>';
        echo '<button type="button" class="button" id="add-sms-recipient">Add Phone Number</button>';
        echo '<p class="description">Max 5 numbers. Format: 5551234567</p>';
        echo '</td>';
        echo '</tr>';

        echo '</table>';
        submit_button('Save Text Notification Settings');
        echo '</form>';
    }

    echo '</div>';
}

add_action('admin_init', function () {
    register_setting('hgm_notification_settings', 'hgm_notification_settings');
});