<?php
/** Disposable local WordPress and MySQL only. All outbound HTTP and mail are intercepted. */
$root = getenv('IEB_MULTI_TEST_WP') ?: getenv('IEB_TEST_WP');
$path=(string) realpath($root ?: '');
$multi=str_ends_with($path,'/ieb-sms-multi-recipient-test/wp');
if (!$root || (!$multi && !str_ends_with($path, '/ieb-sms-isolated-db/wp'))) throw new RuntimeException('Disposable WordPress required');
define('DISABLE_WP_CRON',true);
require $root . '/wp-load.php';
if ($multi ? (DB_NAME !== 'ieb_multi_test' || strpos(DB_HOST,'ieb-sms-multi-recipient-test/mysql.sock')===false) : (DB_NAME !== 'ieb_test' || strpos(DB_HOST, 'ieb-sms-isolated-db/mysql.sock') === false)) throw new RuntimeException('Isolated MySQL required');
function test_sms_cutover($value, $message) { if (!$value) throw new RuntimeException($message); }
add_filter('pre_http_request', fn() => throw new RuntimeException('Outbound HTTP blocked'), PHP_INT_MIN);
$mails = [];
add_filter('pre_wp_mail', function ($pre, $atts) use (&$mails) { $mails[] = $atts; return true; }, PHP_INT_MIN, 2);
add_filter('pre_option_hgm_klaviyo_api_key', fn() => '');
$legacy = ['sms_recipients' => [['phone' => '2025550191', 'carrier' => 'verizon']]];
$interest = ['opt_in' => true, 'phone' => '2025550191', 'disclosure_version' => 'prelaunch-1'];
add_filter('pre_option_hgm_notification_settings', fn() => $legacy);
add_filter('pre_option_ieb_planned_sms_interest', fn() => $interest);
add_filter('pre_option__ieb_sms_recipient', fn() => []);
add_filter('pre_option_hgm_email_settings', fn() => ['sales_team_emails' => 'sales@example.invalid']);
wp_set_current_user(1);
define('IEB_ADMIN_MENU_SLUG', 'instant-estimate-builder');
define('HGM_PLUGIN_PATH', dirname(__DIR__) . '/');
require_once ABSPATH . 'wp-admin/includes/template.php';
require HGM_PLUGIN_PATH . 'includes/sms-client.php';
require HGM_PLUGIN_PATH . 'includes/notifications-settings.php';
$_GET = ['tab' => 'text']; ob_start(); hgm_render_notifications_settings_page(); $html = ob_get_clean();
test_sms_cutover(str_contains($html, 'when a new estimate request is submitted'), 'lead-alert explanation missing');
test_sms_cutover(str_contains($html, 'The SMS relay is not configured'), 'unconfigured warning missing');
test_sms_cutover(str_contains($html, 'id="ieb-sms-phone"') && str_contains($html, 'name="opt_in"') && str_contains($html, 'Request verification code'), 'unconfigured enrollment form missing');
$_GET = ['tab' => 'text', 'sms_result' => 'error']; ob_start(); hgm_render_notifications_settings_page(); $error_html = ob_get_clean();
test_sms_cutover(str_contains($error_html, 'Action could not be confirmed'), 'unconfigured enrollment error hidden');
$before = get_option('_ieb_sms_recipient', false);
$submitted = ieb_sms_process_admin(['_wpnonce'=>wp_create_nonce('ieb_sms_enrollment'),'operation'=>'request','phone'=>'2025550191','opt_in'=>'1']);
test_sms_cutover($submitted === false && get_option('_ieb_sms_recipient', false) === $before, 'unconfigured request must fail without enrollment');
test_sms_cutover(!str_contains($html, 'sms-recipient-list') && !str_contains($html, 'Legacy Email-to-Text Notifications'), 'legacy carrier UI visible');
test_sms_cutover(get_option('ieb_planned_sms_interest') === $interest && get_option('hgm_notification_settings') === $legacy, 'saved preferences changed');
test_sms_cutover(get_option('_ieb_sms_recipient', false) === [], 'prelaunch preference became consent');
$_GET = ['tab' => 'text', 'ieb_sms_preview' => '1']; ob_start(); hgm_render_notifications_settings_page(); $preview = ob_get_clean();
test_sms_cutover(str_contains($preview, 'id="ieb-sms-review-phone"') && str_contains($preview, 'type="button" class="button button-primary">Save Text Alerts'), 'inert preview missing');
test_sms_cutover(!str_contains($preview, 'name="action" value="ieb_sms_enrollment"') && !str_contains($preview, 'sms-recipient-list'), 'preview includes live enrollment or legacy form');
$_GET = [];
add_filter('wp_die_ajax_handler', fn() => function () { throw new RuntimeException('AJAX_DONE'); });
add_filter('wp_doing_ajax', fn() => true);
require HGM_PLUGIN_PATH . 'includes/handle-form.php';
global $wpdb;
$posts=$wpdb->posts; $meta=$wpdb->postmeta; $suffix=bin2hex(random_bytes(5));
$tmpPosts='ieb_cutover_posts_'.$suffix; $tmpMeta='ieb_cutover_meta_'.$suffix;
try {
    test_sms_cutover($wpdb->query("CREATE TEMPORARY TABLE `$tmpPosts` LIKE `$posts`") !== false, 'posts temporary table');
    test_sms_cutover($wpdb->query("CREATE TEMPORARY TABLE `$tmpMeta` LIKE `$meta`") !== false, 'meta temporary table');
    $wpdb->posts=$tmpPosts; $wpdb->postmeta=$tmpMeta;
    $form=wp_insert_post(['post_type'=>'instant_quote_form','post_title'=>'Synthetic gutters','post_status'=>'publish']);
    update_post_meta($form,'_hgm_form_data',wp_slash(wp_json_encode([['title'=>'Size','options'=>[['label'=>'Synthetic','low'=>10,'high'=>20]]]])));
    $_POST=wp_slash(['nonce'=>wp_create_nonce('hgm_nonce'),'first_name'=>'Synthetic','email'=>'customer@example.invalid','phone'=>'2025550192','zip_code'=>'00000','form_id'=>(string)$form,'_hgm_form_data'=>wp_json_encode(['step_1'=>['label'=>'Synthetic','min'=>10,'max'=>20]])]);
    ob_start(); try { hgm_submit_quote_form(); throw new RuntimeException('AJAX did not terminate'); } catch (RuntimeException $e) { if ($e->getMessage() !== 'AJAX_DONE') throw $e; }
    $response=json_decode(ob_get_clean(),true);
    test_sms_cutover(!empty($response['success']), 'lead AJAX failed');
    test_sms_cutover(count($mails)===2, 'expected customer and admin email only, never legacy gateway; got '.count($mails));
    test_sms_cutover(get_option('hgm_notification_settings') === $legacy, 'legacy options must be preserved');
    echo "PASS: real WordPress admin, inert preview, prelaunch isolation and AJAX email cutover; outbound HTTP and mail intercepted\n";
} finally {
    $wpdb->posts=$posts; $wpdb->postmeta=$meta;
    $wpdb->query("DROP TEMPORARY TABLE IF EXISTS `$tmpMeta`");
    $wpdb->query("DROP TEMPORARY TABLE IF EXISTS `$tmpPosts`");
}
