<?php
// Run under an isolated WP-CLI process: wp --skip-plugins --skip-themes eval-file tests/lead-unicode-wp.php
// Only TEMPORARY SQL tables are written; no real leads or outbound mail/HTTP.
global $wpdb;
if ( ! defined('WP_CLI') || ! WP_CLI ) { throw new RuntimeException('WP-CLI required'); }
if ( strpos((new ReflectionFunction('wp_mail'))->getFileName(), '/wp-includes/pluggable.php') === false ) { throw new RuntimeException('Core wp_mail required; run with --skip-plugins'); }
$GLOBALS['wp_filter'] = []; // Prevent unrelated site hooks from observing the synthetic lead.
wp_using_ext_object_cache(false);
wp_suspend_cache_addition(true);
add_filter('pre_http_request', function () { throw new RuntimeException('Outbound HTTP blocked'); }, PHP_INT_MIN);
$mails = [];
add_filter('pre_wp_mail', function ($pre, $atts) use (&$mails) { $mails[] = $atts; return true; }, PHP_INT_MIN, 2);
add_filter('pre_option_hgm_klaviyo_api_key', function () { return ''; });
add_filter('pre_option_hgm_notification_settings', function () { return ['sms_recipients' => []]; });
add_filter('pre_option_hgm_email_settings', function () { return ['sales_team_emails' => 'sales@example.invalid', 'reply_to' => 'reply@example.invalid']; });
add_filter('wp_die_ajax_handler', function () { return function () { throw new RuntimeException('WP_AJAX_DONE'); }; });
if (!defined('DOING_AJAX')) { define('DOING_AJAX', true); }
if (!defined('HGM_PLUGIN_PATH')) { define('HGM_PLUGIN_PATH', dirname(__DIR__) . '/'); }
require_once HGM_PLUGIN_PATH . 'includes/handle-form.php';
$posts = $wpdb->posts; $postmeta = $wpdb->postmeta;
$suffix = bin2hex(random_bytes(5));
$tmp_posts = 'ieb_test_posts_' . $suffix; $tmp_meta = 'ieb_test_postmeta_' . $suffix;
function check_ieb($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
try {
    check_ieb($wpdb->query("CREATE TEMPORARY TABLE `$tmp_posts` LIKE `$posts`") !== false, 'temporary posts creation failed');
    check_ieb($wpdb->query("CREATE TEMPORARY TABLE `$tmp_meta` LIKE `$postmeta`") !== false, 'temporary postmeta creation failed');
    $wpdb->posts = $tmp_posts; $wpdb->postmeta = $tmp_meta;
    $form_id = wp_insert_post(['post_type'=>'instant_quote_form','post_title'=>'Unicode test','post_status'=>'publish'], true);
    check_ieb(!is_wp_error($form_id) && $form_id > 0, 'fixture form creation failed');
    $labels = ['1,000–2,000 sq ft', 'Moderate (7/12–9/12)', 'Café — 雪 & "quotes" \\ roof'];
    $steps = [
      ['title'=>'Home size', 'options'=>[['label'=>$labels[0], 'low'=>0, 'high'=>0]]],
      ['title'=>'Pitch', 'options'=>[['label'=>$labels[1], 'low'=>0, 'high'=>0, 'conditional_prices'=>[['step'=>1, 'label'=>$labels[0], 'low'=>7200, 'high'=>16200]]]]],
      ['title'=>'Details', 'options'=>[['label'=>$labels[2], 'low'=>500, 'high'=>1500]]],
    ];
    update_post_meta($form_id, '_hgm_form_data', wp_slash(wp_json_encode($steps)));
    $answers = [];
    foreach ($labels as $i => $label) { $answers['step_' . ($i+1)] = ['label'=>$label, 'min'=>999999, 'max'=>999999]; }
    // Browser JSON is posted raw; WordPress adds slashes before the AJAX handler.
    $_POST = wp_slash(['nonce'=>wp_create_nonce('hgm_nonce'), 'first_name'=>'Test', 'email'=>'test@example.invalid', 'phone'=>'555-0100', 'zip_code'=>'00000', 'form_id'=>(string)$form_id, '_hgm_form_data'=>json_encode($answers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    ob_start();
    try { hgm_submit_quote_form(); throw new RuntimeException('AJAX handler did not terminate'); }
    catch (RuntimeException $e) { if ($e->getMessage() !== 'WP_AJAX_DONE') { throw $e; } }
    $response = json_decode(ob_get_clean(), true);
    check_ieb(!empty($response['success']), 'AJAX response was not successful');
    $lead_id = (int) $wpdb->get_var("SELECT ID FROM `$tmp_posts` WHERE post_type = 'hgm_lead' LIMIT 1");
    check_ieb($lead_id > 0, 'lead missing from temporary SQL table');
    $raw = $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM `$tmp_meta` WHERE post_id = %d AND meta_key = %s", $lead_id, '_hgm_form_data'));
    $stored = json_decode($raw, true);
    check_ieb(is_array($stored), 'stored JSON invalid: ' . json_last_error_msg());
    foreach ($labels as $i => $label) { check_ieb(($stored['step_' . ($i+1)]['label'] ?? null) === $label, 'stored label mismatch for step ' . ($i+1) . ': ' . ($stored['step_' . ($i+1)]['label'] ?? '[missing]')); }
    check_ieb(get_post_meta($lead_id, '_hgm_estimate_low', true) === '$7,700', 'low total wrong');
    check_ieb(get_post_meta($lead_id, '_hgm_estimate_high', true) === '$17,700', 'high total wrong');
    check_ieb(count($mails) === 2, 'expected exactly customer and admin intercepted messages');
    check_ieb(strpos($mails[0]['message'], '7,700') !== false && strpos($mails[0]['message'], '17,700') !== false, 'customer estimate missing');
    foreach ($labels as $label) { check_ieb(strpos($mails[1]['message'], esc_html($label)) !== false, 'admin email label missing: ' . $label); }
    check_ieb(strpos($mails[1]['message'], 'u2013') === false, 'corrupted en dash found in admin email');
    echo "PASS: WordPress-slashed AJAX request, real temporary SQL/meta roundtrip, conditional totals, intercepted customer/admin HTML, Unicode and backslash labels; no external messages\n";
} finally {
    $wpdb->posts = $posts; $wpdb->postmeta = $postmeta;
    $wpdb->query("DROP TEMPORARY TABLE IF EXISTS `$tmp_meta`");
    $wpdb->query("DROP TEMPORARY TABLE IF EXISTS `$tmp_posts`");
}
