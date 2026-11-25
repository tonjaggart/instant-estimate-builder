<?php
// Offline test: a previously selected relay must never fall back to carrier email after removal.
function add_action(...$args) {}
function get_option($key,$default=false) { return $GLOBALS['options'][$key] ?? $default; }
require __DIR__.'/../includes/sms-client.php';
function check_legacy($condition,$message) { if (!$condition) throw new RuntimeException($message); }
check_legacy(function_exists('ieb_sms_legacy_enabled'),'legacy transport decision missing');
check_legacy(ieb_sms_legacy_enabled(),'unconfigured unconverted sites preserve legacy behavior');
$GLOBALS['options']['_ieb_sms_relay_selected']='1';
check_legacy(!ieb_sms_legacy_enabled(),'disconnected relay must not revive carrier messages');
echo "PASS: legacy gateway cannot revive after relay selection\n";
