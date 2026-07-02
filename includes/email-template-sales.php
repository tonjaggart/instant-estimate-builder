<?php
if (!defined("ABSPATH")) {
    exit();
}
$email_settings = get_option("hgm_email_settings", []);
$primary_color = sanitize_hex_color($email_settings["primary_color"] ?? "#013c55");
$company_name  = sanitize_text_field($email_settings["company_name"] ?? "");
$logo_url      = esc_url($email_settings['logo_url'] ?? '');
$sales_emails  = sanitize_textarea_field($email_settings["sales_team_emails"] ?? ""); // will be CSV string

// This file expects $lead_id to be set before inclusion.

if (!isset($lead_id)) {
    // Preview mode fallback
    $lead_id = 0;
}

$first_name = get_post_meta($lead_id, 'first_name', true);
$form_data_raw = get_post_meta($lead_id, '_hgm_form_data', true);
$form_data = is_string($form_data_raw) ? json_decode($form_data_raw, true) : $form_data_raw;
$quote_form_id = get_post_meta($lead_id, '_hgm_form_id', true);
$form_title = get_the_title($quote_form_id);
// Sanitize and prepare sales team emails as array
$sales_emails_array = array_filter(array_map('trim', explode(',', $sales_emails)));

$phone     = get_post_meta($lead_id, 'phone', true);
$email     = get_post_meta($lead_id, 'email', true);
$zip_code  = get_post_meta($lead_id, 'zip_code', true);
$estimate_low  = get_post_meta($lead_id, '_hgm_estimate_low', true);
$estimate_high = get_post_meta($lead_id, '_hgm_estimate_high', true);

// Sanitize and format estimates
$estimate_low  = number_format(floatval(str_replace(['$', ','], '', $estimate_low)));
$estimate_high = number_format(floatval(str_replace(['$', ','], '', $estimate_high)));

// Build HTML table from form data if not already generated
if (empty($quote_table_html) && is_array($form_data)) {
    $quote_table_html = '<table><thead><tr><th>Question</th><th>Answer</th></tr></thead><tbody>';
    foreach ($form_data as $step_id => $data) {
        $label = isset($data['question']) ? esc_html($data['question']) : 'Step ' . esc_html($step_id);
        $answer = isset($data['label']) ? esc_html($data['label']) : '';
        $quote_table_html .= "<tr><td>{$label}</td><td>{$answer}</td></tr>";
    }
    $quote_table_html .= '</tbody></table>';
}

?>
<!DOCTYPE html>
<html>
    <head>
        <meta charset="UTF-8">
        <title>New Lead Notification</title>
        <style>
            body { font-family: Arial, sans-serif; background-color: #f4f4f4; margin: 0; padding: 20px 0; }
            .container { max-width: 600px; margin: 0 auto; background: #fff; border: 1px solid #e0e0e0; }
            .header-logo { padding: 20px; text-align: center; }
            .content { padding: 30px; }
            h2, h3 { color: #1a1a1a; margin-top: 0; }
            table { width: 100%; border-collapse: collapse; margin-top: 20px; }
            th, td { border: 1px solid #ddd; padding: 8px; }
            th { background-color: <?php echo esc_attr($primary_color); ?>; color: white; }
        </style>
    </head>
    <body>
        <span style="display:none; font-size:1px; color:#ffffff; line-height:1px; max-height:0px; max-width:0px; opacity:0; overflow:hidden;">
            A new lead has been submitted via the <?php echo esc_html($form_title); ?> instant estimate form on your website....
        </span>
        <table class="container" cellpadding="0" cellspacing="0">
            <tr>
                <td class="header-logo">
                    <?php if ($logo_url): ?>
                    <img src="<?php echo esc_url($logo_url); ?>" alt="<?php echo esc_attr($company_name); ?> Logo" style="max-width: 200px; height: auto;">
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <td class="content">
                    <h2>You Have a New <?php echo esc_html($form_title); ?> Lead!</h2>
                    <p>A new lead has been submitted via the <?php echo esc_html($form_title); ?> instant estimate form. Below are the details:</p>
                    <ul>
                        <li><strong>Name:</strong> <?php echo esc_html($first_name); ?></li>
                        <li><strong>Email:</strong> <?php echo esc_html($email); ?></li>
                        <li><strong>Phone:</strong> <?php echo esc_html($phone); ?></li>
                        <li><strong>Zip Code:</strong> <?php echo esc_html($zip_code); ?></li>
                       <li><strong>Estimate Range:</strong> $<?php echo number_format((float) str_replace(',', '', $estimate_low)); ?> – $<?php echo number_format((float) str_replace(',', '', $estimate_high)); ?></li>
                    </ul>
                    <p>Below are the lead's responses:</p>
                    <?php
                    if (!empty($form_data) && is_array($form_data)) {

                        // Debug: Log the quote form ID
                        error_log('Quote Form ID: ' . $quote_form_id);

                        // Load saved step structure from the quote form post
                        error_log('Sales email - using quote_form_id: ' . $quote_form_id);
                        $quote_form_data_raw = get_post_meta($quote_form_id, '_hgm_form_data', true);
                        $quote_form_data = is_string($quote_form_data_raw) ? json_decode($quote_form_data_raw, true) : $quote_form_data_raw;

                        // Debug: Log the form data structure
                        error_log('Quote Form Data: ' . print_r($quote_form_data, true));

                        echo '<table>';
                        echo '<tr><th>Question</th><th>Selected Answer</th></tr>';
                        error_log('Sales email - quote_form_data: ' . print_r($quote_form_data, true));
                        foreach ($form_data as $step_key => $answer_data) {
                            $step_number = intval(str_replace('step_', '', $step_key));

                           $question_title = $quote_form_data[$step_number - 1]['title'] ?? "[Missing title for step $step_number]";

                            $answer_label = isset($answer_data['label']) ? sanitize_text_field($answer_data['label']) : 'N/A';

                            echo '<tr>';
                            echo '<td>' . esc_html($question_title) . '</td>';
                            echo '<td>' . esc_html($answer_label) . '</td>';
                            echo '</tr>';
                        }

                        echo '</table>';
                    }
                    ?>
                </td>
            </tr>
            <tr>
                <td style="padding: 20px; text-align: center; font-size: 12px; color: #999;">
                    © <?php echo date("Y"); ?> <?php echo esc_html($company_name); ?>. All rights reserved.
                </td>
            </tr>
        </table>
    </body>
</html>