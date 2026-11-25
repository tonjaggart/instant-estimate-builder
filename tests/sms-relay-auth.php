<?php
// Run: php tests/sms-relay-auth.php. Isolated local SQLite only; no HTTP or SMS.
require_once __DIR__ . '/../sms-relay/auth.php';
function check($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
function fails($callable, $message) {
    try { $callable(); } catch (InvalidArgumentException $e) { return; }
    throw new RuntimeException($message);
}
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
ieb_auth_install_schema($db);
$key = ieb_auth_generate_key();
check((bool)preg_match('/\A[0-9a-f]{64}\z/D', $key), 'generated key must encode 32 bytes');
check($key !== ieb_auth_generate_key(), 'generated keys unexpectedly identical');
$id = 'installation_123';
$now = 1800000000;
$body = '{"event":"example"}';
$signature = ieb_auth_sign($key, $id, 'POST', '/v1/events', (string)$now, str_repeat('a', 32), $body);
check(ieb_auth_verify($db, $key, $id, 'POST', '/v1/events', (string)$now, str_repeat('a', 32), $body, $signature, $now) === true, 'valid request rejected');
check(ieb_auth_verify($db, $key, $id, 'POST', '/v1/events', (string)$now, str_repeat('a', 32), $body, $signature, $now) === false, 'replayed nonce accepted');
foreach (['body', 'method', 'path', 'install', 'signature'] as $change) {
    $nonce = bin2hex(random_bytes(16));
    $sig = ieb_auth_sign($key, $id, 'POST', '/v1/events', (string)$now, $nonce, $body);
    $ok = ieb_auth_verify($db, $key, $change === 'install' ? 'other_install' : $id, $change === 'method' ? 'GET' : 'POST', $change === 'path' ? '/v1/other' : '/v1/events', (string)$now, $nonce, $change === 'body' ? $body . 'x' : $body, $change === 'signature' ? str_repeat('0', 64) : $sig, $now);
    check($ok === false, 'tampered ' . $change . ' accepted');
    check(ieb_auth_verify($db, $key, $id, 'POST', '/v1/events', (string)$now, $nonce, $body, $sig, $now), 'invalid request consumed a nonce');
}
foreach ([$now - 301, $now + 301] as $timestamp) {
    $nonce = bin2hex(random_bytes(16));
    $sig = ieb_auth_sign($key, $id, 'POST', '/v1/events', (string)$timestamp, $nonce, $body);
    check(!ieb_auth_verify($db, $key, $id, 'POST', '/v1/events', (string)$timestamp, $nonce, $body, $sig, $now), 'out-of-window timestamp accepted');
}
foreach ([$now - 300, $now + 300] as $timestamp) {
    $nonce = bin2hex(random_bytes(16));
    $sig = ieb_auth_sign($key, $id, 'POST', '/v1/events', (string)$timestamp, $nonce, $body);
    check(ieb_auth_verify($db, $key, $id, 'POST', '/v1/events', (string)$timestamp, $nonce, $body, $sig, $now), 'window boundary rejected');
}
fails(function () use ($id, $now, $body) { ieb_auth_sign('weak', $id, 'POST', '/v1/events', (string)$now, str_repeat('a',32), $body); }, 'weak secret accepted');
fails(function () use ($key, $id, $now, $body) { ieb_auth_sign($key, $id, 'POST', '/v1/events', (string)$now, "bad\nnonce", $body); }, 'bad nonce accepted');
check(!ieb_auth_verify($db, $key, $id, 'POST', '/v1/events', '01800000000', str_repeat('b',32), $body, str_repeat('0',64), $now), 'noncanonical timestamp accepted');
echo "SMS relay authentication tests passed\n";
