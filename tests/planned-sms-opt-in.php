<?php
// Standalone tests: planned SMS interest is never a live sending entitlement.
define('ABSPATH', __DIR__);
function add_action(...$args) {}
function add_filter(...$args) {}
function sanitize_key($s) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower($s)); }
function get_current_user_id() { return 123; }
function current_time($type, $gmt = false) { return '2026-11-16 00:00:00'; }
function get_option($key, $default = []) { return $GLOBALS['old'] ?? $default; }
require dirname(__DIR__) . '/includes/notifications-settings.php';
function check($v, $message) { if (!$v) { fwrite(STDERR, "FAIL: $message\n"); exit(1); } }
$GLOBALS['old'] = [];
$a = ieb_sanitize_planned_sms_interest(['phone' => '(555) 555-0123', 'opt_in' => '1']);
check($a['phone'] === '5555550123' && $a['opt_in'] === true && $a['user_id'] === 123 && !empty($a['recorded_at']), 'affirmative opt-in records normalized phone, user and time');
$again = ieb_sanitize_planned_sms_interest($a);
check($again['opt_in'] === true && $again['phone'] === '5555550123', 'initial option creation survives double sanitization');
$GLOBALS['old'] = $a;
$b = ieb_sanitize_planned_sms_interest(['phone' => '5555550123', 'opt_in' => '1']);
check($b['recorded_at'] === $a['recorded_at'], 'unchanged opt-in retains timestamp');
$c = ieb_sanitize_planned_sms_interest(['phone' => '5555550123']);
check(empty($c['opt_in']) && empty($c['phone']), 'withdrawal clears opt-in and phone');
$d = ieb_sanitize_planned_sms_interest(['phone' => '123', 'opt_in' => '1']);
check(empty($d['opt_in']) && empty($d['phone']), 'invalid phone cannot opt in');
$e = ieb_sanitize_planned_sms_interest(['phone' => '5555550124', 'opt_in' => '1']);
check($e['phone'] === '5555550124' && $e['opt_in'] === true, 'changed phone requires explicit checkbox');
$f = ieb_sanitize_planned_sms_interest(['phone' => '5555550124']);
check(empty($f['opt_in']), 'changed phone without checkbox is not opted in');
echo "PASS: opt-in, stable record, withdrawal, invalid phone, changed phone\n";
