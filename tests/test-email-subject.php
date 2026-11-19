<?php
// Exercise the real admin-post callback with mail intercepted before delivery.
define('ABSPATH', __DIR__);
$GLOBALS['actions'] = [];
function add_action($name, $callback) { $GLOBALS['actions'][$name] = $callback; }
function plugin_dir_path($file) { return dirname($file) . '/'; }
function current_user_can($cap) { return $cap === 'manage_options'; }
function check_admin_referer($action, $name) { if ($action !== 'hgm_send_test_email_action' || $name !== 'hgm_send_test_email_nonce') throw new RuntimeException('Incorrect nonce check'); }
function sanitize_email($v) { return $v; }
function get_option($name, $default = []) { return $default; }
function esc_url($v) { return $v; }
function sanitize_text_field($v) { return $v; }
function wp_kses_post($v) { return $v; }
function get_bloginfo($key) { return 'admin@example.invalid'; }
function sanitize_hex_color($v) { return $v; }
function hgm_get_customer_email_html($lead_id = null) { return '<p>preview</p>'; }
class MailIntercepted extends Exception {}
function wp_mail($to, $subject, $message, $headers) {
    $GLOBALS['captured_mail'] = compact('to', 'subject', 'message', 'headers');
    throw new MailIntercepted(); // Stop before transient, redirect and exit; no transport exists.
}
require dirname(__DIR__) . '/includes/email-settings.php';
$_POST['hgm_test_email'] = 'test@example.invalid';
if (!isset($GLOBALS['actions']['admin_post_hgm_send_test_email'])) throw new RuntimeException('Handler not registered');
try {
    $GLOBALS['actions']['admin_post_hgm_send_test_email']();
    throw new RuntimeException('Handler did not call intercepted wp_mail');
} catch (MailIntercepted $e) {}
$mail = $GLOBALS['captured_mail'];
$expected = '[PREVIEW] Instant Online Estimate for Customer';
if ($mail['subject'] !== $expected) {
    fwrite(STDERR, 'Incorrect subject: ' . var_export($mail['subject'], true) . "\n");
    exit(1);
}
if ($mail['to'] !== 'test@example.invalid' || $mail['message'] !== '<p>preview</p>') throw new RuntimeException('Unexpected preview recipient or body');
echo "PASS: actual preview handler uses exact subject; mail intercepted without delivery\n";
