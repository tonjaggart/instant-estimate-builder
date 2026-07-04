<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}
add_action('wp_ajax_hgm_submit_quote_form', 'hgm_submit_quote_form');
add_action('wp_ajax_nopriv_hgm_submit_quote_form', 'hgm_submit_quote_form');
add_action('wp_ajax_hgm_get_quote_nonce', 'hgm_get_quote_nonce');
add_action('wp_ajax_nopriv_hgm_get_quote_nonce', 'hgm_get_quote_nonce');

function hgm_get_quote_nonce() {
    nocache_headers();
    wp_send_json_success([
        'nonce' => wp_create_nonce('hgm_nonce'),
    ]);
}

function hgm_send_to_klaviyo($lead_id, $form_id, $first_name, $email, $phone, $form_title, $estimate_low, $estimate_high) {

    // ======================
    // DEBUG MODE CHECK
    // ======================
    $hgm_debug = defined('WP_DEBUG') && WP_DEBUG;

    // ======================
    // BASIC SAFETY CHECKS
    // ======================

    // Get Klaviyo API key from Integrations settings
    $api_key = get_option('hgm_klaviyo_api_key');

    if (empty($api_key)) {
        error_log('Klaviyo skipped: Missing API key');
        return;
    }

    // Check if Klaviyo is enabled for this specific form
    $klaviyo_enabled = get_post_meta($form_id, 'hgm_enable_klaviyo', true);

    if (!$klaviyo_enabled) {
        error_log('Klaviyo skipped: Not enabled for this form');
        return;
    }

    // Get the Klaviyo List ID assigned to this form
    $list_id = get_post_meta($form_id, 'hgm_klaviyo_list_id', true);

    if (empty($list_id)) {
        error_log('Klaviyo skipped: Missing list ID');
        return;
    }

    if ($hgm_debug) {
        error_log('Klaviyo ready to send for lead ID: ' . $lead_id);
    }

    // ======================
    // NORMALIZE PHONE
    // ======================

    // Remove anything that is not a digit
    $clean_phone = preg_replace('/\D/', '', $phone);

    // Convert 10-digit US number to E.164 format
    if (strlen($clean_phone) === 10) {
        $clean_phone = '+1' . $clean_phone;
    } else {
        $clean_phone = '';
    }

    // ======================
    // DEBUG PAYLOAD
    // ======================

    $payload = [
        'email'         => $email,
        'first_name'    => $first_name,
        'phone'         => $clean_phone,
        'form_name'     => $form_title,
        'estimate_low'  => $estimate_low,
        'estimate_high' => $estimate_high,
    ];

    if ($hgm_debug) {
        error_log('Klaviyo payload prepared for lead sync.');
    }

    // ======================
    // CREATE PROFILE
    // ======================

    $profile_response = wp_remote_post('https://a.klaviyo.com/api/profiles/', [
        'headers' => [
            'Authorization' => 'Klaviyo-API-Key ' . $api_key,
            'Content-Type'  => 'application/json',
            'revision'      => '2023-10-15',
        ],
        'body' => wp_json_encode([
            'data' => [
                'type' => 'profile',
                'attributes' => [
                    'email'        => $email,
                    'first_name'   => $first_name,
                    'phone_number' => $clean_phone,
                    'properties'   => [
                        'Form Name'     => $form_title,
                        'Estimate Low'  => $estimate_low,
                        'Estimate High' => $estimate_high,
                    ],
                ],
            ],
        ]),
        'timeout' => 20,
    ]);

    if (is_wp_error($profile_response)) {
        error_log('Klaviyo profile request error: ' . $profile_response->get_error_message());
        return;
    }

    $profile_status = wp_remote_retrieve_response_code($profile_response);
    $profile_body_raw = wp_remote_retrieve_body($profile_response);
    $profile_body = json_decode($profile_body_raw, true);

    if ($hgm_debug) {
        error_log('Klaviyo profile response status: ' . $profile_status);
    }

    $profile_id = '';

    // New profile created successfully
    if (!empty($profile_body['data']['id'])) {
        $profile_id = $profile_body['data']['id'];
        error_log('Klaviyo profile created. Profile ID: ' . $profile_id);
    }

    // Existing profile found. Klaviyo returns duplicate_profile_id on 409.
    if (empty($profile_id) && $profile_status === 409 && !empty($profile_body['errors'][0]['meta']['duplicate_profile_id'])) {
        $profile_id = $profile_body['errors'][0]['meta']['duplicate_profile_id'];
        error_log('Klaviyo duplicate profile found. Using existing Profile ID: ' . $profile_id);
    }

    // Stop if no profile ID was found
    if (empty($profile_id)) {
        error_log('Klaviyo profile failed. Status: ' . $profile_status);
        return;
    }

    // ======================
    // ADD PROFILE TO LIST
    // ======================

    $list_response = wp_remote_post("https://a.klaviyo.com/api/lists/{$list_id}/relationships/profiles/", [
        'headers' => [
            'Authorization' => 'Klaviyo-API-Key ' . $api_key,
            'Content-Type'  => 'application/json',
            'revision'      => '2023-10-15',
        ],
        'body' => wp_json_encode([
            'data' => [
                [
                    'type' => 'profile',
                    'id'   => $profile_id,
                ],
            ],
        ]),
        'timeout' => 20,
    ]);

    if (is_wp_error($list_response)) {
        error_log('Klaviyo list request error: ' . $list_response->get_error_message());
        return;
    }

    $list_status = wp_remote_retrieve_response_code($list_response);
    $list_body = wp_remote_retrieve_body($list_response);

    if ($hgm_debug) {
        error_log('Klaviyo list response status: ' . $list_status);
    }

    if ($list_status >= 200 && $list_status < 300) {
        error_log('Klaviyo SUCCESS: Lead added to list. Profile ID: ' . $profile_id);
    } else {
        error_log('Klaviyo list add failed. Status: ' . $list_status);
    }
}

require_once HGM_PLUGIN_PATH . 'includes/email-template-customer.php';

function hgm_calculate_server_side_estimate($form_id, $submitted_form_data) {
    $submitted_form_data = is_array($submitted_form_data) ? $submitted_form_data : [];
    $safe_form_data = [];
    $low_total = 0;
    $high_total = 0;

    $form_id = absint($form_id);
    $saved_steps = $form_id ? get_post_meta($form_id, '_hgm_form_data', true) : [];

    if (is_string($saved_steps)) {
        $saved_steps = json_decode($saved_steps, true);
    }

    if (!is_array($saved_steps)) {
        $saved_steps = [];
    }

    foreach ($submitted_form_data as $step_key => $submitted_step) {
        $step_key = sanitize_key($step_key);
        $submitted_label = sanitize_text_field($submitted_step['label'] ?? '');
        $matched = false;

        if (preg_match('/^step_(\d+)$/', $step_key, $matches)) {
            $step_index = max(0, absint($matches[1]) - 1);
            $saved_step = $saved_steps[$step_index] ?? [];
            $options = is_array($saved_step['options'] ?? null) ? $saved_step['options'] : [];

            foreach ($options as $option) {
                $option_label = sanitize_text_field($option['label'] ?? '');
                if ($submitted_label !== '' && hash_equals($option_label, $submitted_label)) {
                    $low = isset($option['low']) && is_numeric($option['low']) ? floatval($option['low']) : 0;
                    $high = isset($option['high']) && is_numeric($option['high']) ? floatval($option['high']) : 0;

                    $safe_form_data[$step_key] = [
                        'label' => $option_label,
                        'min' => $low,
                        'max' => $high,
                    ];
                    $low_total += $low;
                    $high_total += $high;
                    $matched = true;
                    break;
                }
            }
        }

        if (!$matched && $submitted_label !== '') {
            // Preserve the answer label for lead review, but do not trust client-supplied pricing.
            $safe_form_data[$step_key] = [
                'label' => $submitted_label,
                'min' => 0,
                'max' => 0,
            ];
        }
    }

    return [
        'form_data' => $safe_form_data,
        'low_total' => $low_total,
        'high_total' => $high_total,
    ];
}

function hgm_submit_quote_form() {

    // ✅ Nonce verification (MUST be first)
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'hgm_nonce')) {
        error_log('HGM AJAX: Nonce verification failed');
        wp_send_json_error(['message' => 'Nonce verification failed.']);
    }

    // Sanitize fields
    $first_name = sanitize_text_field($_POST['first_name'] ?? '');
    $email      = sanitize_email($_POST['email'] ?? '');
    $phone      = sanitize_text_field($_POST['phone'] ?? '');
    $zip_code   = sanitize_text_field($_POST['zip_code'] ?? '');
    $form_id    = isset($_POST['form_id']) ? absint($_POST['form_id']) : 0;

    if (!$form_id || get_post_type($form_id) !== 'instant_quote_form') {
        wp_send_json_error(['message' => 'Invalid estimate form.']);
    }

    $form_data_json = wp_unslash($_POST['_hgm_form_data'] ?? '');
    $form_data = json_decode($form_data_json, true);
    $calculated_estimate = hgm_calculate_server_side_estimate($form_id, $form_data);
    $form_data = $calculated_estimate['form_data'];
    $low_total = $calculated_estimate['low_total'];
    $high_total = $calculated_estimate['high_total'];

    $errors = [];

    if (empty($first_name)) $errors['first_name'] = 'First name is required.';
    if (empty($email) || !is_email($email)) $errors['email'] = 'A valid email is required.';
    if (empty($phone)) $errors['phone'] = 'Phone number is required.';
    if (empty($zip_code)) $errors['zip_code'] = 'Zip code is required.';

    if (!empty($errors)) {
        error_log('HGM AJAX: Validation failed - ' . json_encode($errors));
        wp_send_json_error(['errors' => $errors]);
    }

    // Create a new lead post
    $lead_id = wp_insert_post([
        'post_type'   => 'hgm_lead',
        'post_title'  => $first_name,
        'post_status' => 'publish',
    ]);

    if (is_wp_error($lead_id) || !$lead_id) {
        wp_send_json_error(['message' => 'Failed to save lead.']);
        wp_die();
    }

    update_post_meta($lead_id, '_hgm_form_id', $form_id);
    update_post_meta($lead_id, '_hgm_form_data', wp_json_encode($form_data));

    update_post_meta($lead_id, 'first_name', $first_name);
    update_post_meta($lead_id, 'email', $email);
    update_post_meta($lead_id, 'phone', $phone);
    update_post_meta($lead_id, 'zip_code', $zip_code);
    update_post_meta($lead_id, 'created_at', current_time('mysql'));


    // Save estimate totals meta (without sanitizing to preserve $)
    update_post_meta($lead_id, '_hgm_estimate_low', '$' . number_format(floatval($low_total), 0));
    update_post_meta($lead_id, '_hgm_estimate_high', '$' . number_format(floatval($high_total), 0));

    // Confirm meta saved correctly
    $check_low = get_post_meta($lead_id, '_hgm_estimate_low', true);
    $check_high = get_post_meta($lead_id, '_hgm_estimate_high', true);

    $quote_form_id = get_post_meta($lead_id, '_hgm_form_id', true);
    $form_title = get_the_title($quote_form_id);
    $quote_form_data = $form_id ? get_post_meta($form_id, '_hgm_form_data', true) : [];
    if (is_string($quote_form_data)) {
        $quote_form_data = json_decode($quote_form_data, true);
    }
    if (!is_array($quote_form_data)) {
        $quote_form_data = [];
    }


    // ======================
    // KLAVIYO INTEGRATION
    // ======================
    hgm_send_to_klaviyo(
        $lead_id,
        $form_id,
        $first_name,
        $email,
        $phone,
        $form_title,
        $low_total,
        $high_total
    );

    // --- Email sending starts here ---

    // Load email settings
    $email_settings = get_option('hgm_email_settings', []);
    $reply_to = sanitize_email($email_settings['reply_to'] ?? get_bloginfo('admin_email'));
    $primary_color = sanitize_hex_color($email_settings['primary_color'] ?? '#013c55');

    // Email recipient and subject for customer
    $to = $email;
    // Get form-specific subject
    $form_subject = $form_id ? trim(get_post_meta($form_id, 'hgm_email_subject', true)) : '';

    // Default subject (WITHOUT name or dash)
    $default_subject = 'Your HVAC Estimate is Ready!';

    // Use custom subject if it exists, otherwise fallback
    $subject_text = !empty($form_subject) ? $form_subject : $default_subject;

    // ALWAYS prepend first name + dash
    $subject = $first_name . ' - ' . $subject_text;

    $lead_id = (int) $lead_id;

    $visitor_email_message = hgm_get_customer_email_html($lead_id);


    $headers = [];

    if (!empty($reply_to)) {
        $headers[] = 'Reply-To: ' . $reply_to;
    }

    $headers[] = 'Content-Type: text/html; charset=UTF-8';

    // Send email to customer
    $mail_sent = wp_mail($to, $subject, $visitor_email_message, $headers);

    if (!$mail_sent) {
        error_log('HGM AJAX: Customer email sending failed for lead ID ' . $lead_id);
    } else {
        error_log('HGM AJAX: Customer email sent successfully for lead ID ' . $lead_id);
    }

    // Get sales team emails as CSV string from settings
    $email_settings = get_option('hgm_email_settings', []);
    $sales_team_emails = sanitize_textarea_field($email_settings['sales_team_emails'] ?? '');
    $sales_emails_array = array_filter(array_map('trim', explode(',', $sales_team_emails)));


    $quote_table_html = '';

    if (is_array($form_data)) {
        $quote_table_html .= '<table style="width:100%; border-collapse:collapse; font-family: sans-serif;">';
        $quote_table_html .= '<thead><tr><th style="text-align:left; border-bottom:1px solid #ccc; padding:8px;">Question</th><th style="text-align:left; border-bottom:1px solid #ccc; padding:8px;">Answer</th></tr></thead><tbody>';

        $step_index = 1;

        foreach ($form_data as $step_data) {
            $question_title = 'Step ' . $step_index;

            // If matching step exists in the estimate form structure, use its title
            if (!empty($quote_form_data[$step_index - 1]['title'])) {
                $question_title = esc_html($quote_form_data[$step_index - 1]['title']);
            }

            $answer = isset($step_data['label']) ? esc_html($step_data['label']) : '-';

            $quote_table_html .= '<tr>';
            $quote_table_html .= '<td style="padding:8px; border-bottom:1px solid #eee;">' . $question_title . '</td>';
            $quote_table_html .= '<td style="padding:8px; border-bottom:1px solid #eee;">' . $answer . '</td>';
            $quote_table_html .= '</tr>';

            $step_index++;
        }

        $quote_table_html .= '</tbody></table>';
    }

    if (!empty($sales_emails_array)) {

        $sales_email_data = [
            'date'             => date('F j, Y g:i a'),
            'first_name'       => $first_name,
            'email'            => $email,
            'phone'            => $phone,
            'zip_code'         => $zip_code,
            'quote_table_html' => $quote_table_html,
        ];

        extract($sales_email_data); // ✅ Move this above the include

        ob_start();
        $quote_form_id = get_post_meta($lead_id, '_hgm_form_id', true); // Retrieve saved form ID
        include HGM_PLUGIN_PATH . 'includes/email-template-sales.php'; // ✅ This template expects extracted vars
        $sales_email_message = ob_get_clean();

        $subject_sales = $first_name . ' - New ' . $form_title . ' Estimate Lead';

        $headers = ['Content-Type: text/html; charset=UTF-8'];
        if (!empty($email_settings['reply_to'])) {
            $headers[] = 'Reply-To: ' . sanitize_email($email_settings['reply_to']);
        }

        foreach ($sales_emails_array as $sales_email) {
            $result = wp_mail($sales_email, $subject_sales, $sales_email_message, $headers);
            if (!$result) {
                error_log('HGM SALES EMAIL FAILED for lead ID ' . $lead_id);
            } else {
                error_log('HGM SALES EMAIL SENT for lead ID ' . $lead_id);
            }
        }
    }
    // ✅ Text Message Notifications
    $sms_settings = get_option('hgm_notification_settings', []);
    $sms_recipients = $sms_settings['sms_recipients'] ?? [];

    if (!empty($sms_recipients) && is_array($sms_recipients)) {
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $sms_subject = ''; // Subject not needed for SMS
        $estimate_low = get_post_meta($lead_id, '_hgm_estimate_low', true);
        $estimate_high = get_post_meta($lead_id, '_hgm_estimate_high', true);

        // ✅ Correct edit link for the custom view page
        $edit_link = admin_url('admin.php?page=instant-estimate-lead&id=' . $lead_id);

        // ✅ Build the SMS body
        // Use the sanitized submit value first, then fall back to saved lead meta.
        // Label each line so email-to-SMS gateways do not make the customer name look missing.
        $first_name_for_sms = trim($first_name);
        if ($first_name_for_sms === '') {
            $first_name_for_sms = trim((string) get_post_meta($lead_id, 'first_name', true));
        }

        $sms_body  = "New " . $form_title . " Estimate:\n";
        $sms_body .= "Name: {$first_name_for_sms}\n";
        $sms_body .= "Phone: {$phone}\n";
        $sms_body .= "ZIP: {$zip_code}\n";
        $sms_body .= "Estimate: {$estimate_low} - {$estimate_high}\n";
        //$sms_body .= $edit_link;

        foreach ($sms_recipients as $recipient) {
            $clean_phone = preg_replace('/\D/', '', $recipient['phone'] ?? '');
            $carrier = sanitize_text_field($recipient['carrier'] ?? '');

            $carrier_domains = [
                'verizon'     => '@vtext.com',
                'att'         => '@txt.att.net',
                'tmobile'     => '@tmomail.net',
                'sprint'      => '@messaging.sprintpcs.com',
                'uscellular'  => '@email.uscc.net',
            ];

            if (strlen($clean_phone) === 10 && isset($carrier_domains[$carrier])) {
                $to_sms = $clean_phone . $carrier_domains[$carrier];
                $headers = ['Content-Type: text/plain; charset=UTF-8'];

                $sent = wp_mail($to_sms, $sms_subject, $sms_body, $headers);
                error_log($sent ? 'SMS notification sent for lead ID ' . $lead_id : 'SMS notification failed for lead ID ' . $lead_id);
            }
        }
    }
    wp_send_json_success([
        'message' => 'Estimate submitted successfully'
    ]);
    wp_die();
}
