<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('hgm_get_customer_email_html')) {
    function hgm_get_customer_email_html($lead_id = null) {
        ob_start();

        // Check if this is preview mode
        $is_preview = is_null($lead_id);

        // Get saved settings
        $email_settings = get_option("hgm_email_settings", []);

        $logo_url        = esc_url($email_settings['logo_url'] ?? '');
        $phone_number    = sanitize_text_field($email_settings["phone_number"] ?? "");
        $sanitized_phone = preg_replace('/[^0-9]/', '', $phone_number);
        $phone_text      = sanitize_text_field($email_settings["phone_text"] ?? "Call Us:");
        $button_text     = sanitize_text_field($email_settings["button_text"] ?? "");
        $button_link     = esc_url($email_settings["button_link"] ?? "");
        $body_copy       = sanitize_textarea_field($email_settings["body_copy"] ?? "");
        $disclaimer      = wp_kses_post($email_settings["disclaimer"] ?? "");
        $text_below_btn  = wp_kses_post($email_settings["text_below_btn"] ?? "");
        $company_name    = sanitize_text_field($email_settings["company_name"] ?? "");
        $reply_to        = sanitize_email($email_settings["reply_to"] ?? get_bloginfo("admin_email"));
        $primary_color   = sanitize_hex_color($email_settings["primary_color"] ?? "#013c55");

        if ($is_preview) {
            $first_name    = "Jon";
            $estimate_low  = "$8,700";
            $estimate_high = "$12,900";
        } else {
            $lead_id       = (int) $lead_id;
            $first_name    = get_post_meta($lead_id, 'first_name', true);
            $form_wrapper  = get_post_meta($lead_id, '_hgm_form_data', true);

            if (!$first_name && isset($form_wrapper['first_name'])) {
                $first_name = sanitize_text_field($form_wrapper['first_name']);
            }

            $estimate_low  = get_post_meta($lead_id, '_hgm_estimate_low', true);
            $estimate_high = get_post_meta($lead_id, '_hgm_estimate_high', true);
        }

        include HGM_PLUGIN_PATH . 'includes/email-customer-html.php';

        return ob_get_clean();
    }
}