<?php
// Offline behavioral tests: no provider calls, HTTP requests or real recipients.
require_once __DIR__ . '/../sms-relay/queue.php';
function check_queue($ok, $message) { if (!$ok) throw new RuntimeException($message); }
function reject_queue($fn, $message) { try { $fn(); } catch (DomainException $e) { return; } throw new RuntimeException($message); }
$db = new PDO('sqlite::memory:'); $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
$q = new IebSmsQueue($db, $key); $q->install();
$phone = '+12025550101'; $lead = ['name'=>'Synthetic Person','phone'=>'+12025550102','service'=>'Synthetic Service'];
$q->configureInstallation('install_123', 3, true);
reject_queue(fn() => $q->enqueue('install_123', 'lead_1', $phone, $lead, 1700000000), 'unconsented enqueue accepted');
$q->consent('install_123', $phone, 'admin_123', 'notice-v1', 1700000000);
check_queue($q->enqueue('install_123', 'lead_1', $phone, $lead, 1700000001) === 'queued', 'enqueue');
check_queue($q->enqueue('install_123', 'lead_1', $phone, $lead, 1700000002) === 'duplicate', 'idempotent duplicate');
$changed = $lead; $changed['name'] = 'Other';
reject_queue(fn() => $q->enqueue('install_123', 'lead_1', $phone, $changed, 1700000003), 'event payload conflict accepted');
$send = function($to, $message) use (&$calls, $phone) { $calls[] = [$to,$message]; check_queue($to === $phone, 'recipient mismatch'); return 'accepted'; };
$calls=[];
check_queue($q->dispatchOne($send, 1700000004) === 'accepted', 'dispatch');
check_queue(count($calls) === 1 && strpos($calls[0][1], 'Taggart') !== false && strpos($calls[0][1], 'Synthetic Person') !== false && strpos($calls[0][1], 'Synthetic Service') !== false && strpos($calls[0][1], '+12025550102') !== false, 'message missing sender branding or lead data');
check_queue($q->dispatchOne($send, 1700000005) === 'empty' && count($calls) === 1, 'duplicate dispatch');
$q->enqueue('install_123', 'lead_2', $phone, $lead, 1700000006);
check_queue($q->dispatchOne(fn() => 'retryable', 1700000007) === 'unknown', 'provider attempt must remain charged');
$q->stop($phone, 1700000008);
check_queue($q->dispatchOne($send, 1700001000) === 'empty' && count($calls) === 1, 'STOP did not cancel retry');
reject_queue(fn() => $q->enqueue('install_123', 'lead_3', $phone, $lead, 1700001001), 'STOP bypassed');
$q->consent('install_123', $phone, 'admin_123', 'notice-v1', 1700001002);
reject_queue(fn() => $q->enqueue('install_123', 'lead_3', $phone, $lead, 1700001003), 'consent bypassed STOP');
// Explicit provider-side START removes suppression but is not business consent.
$q->start($phone, 1700001004);
reject_queue(fn() => $q->enqueue('install_123', 'lead_3', $phone, $lead, 1700001005), 'START restored business consent');
$q->consent('install_123', $phone, 'admin_123', 'notice-v1', 1700001006);
$q->enqueue('install_123', 'lead_3', $phone, $lead, 1700001007);
check_queue($q->dispatchOne(fn() => 'unknown', 1700001008) === 'unknown', 'uncertain send');
$q->enqueue('install_123', 'lead_4', $phone, $lead, 1700001009);
check_queue($q->dispatchOne($send, 1700001010) === 'capped', 'uncertain send must reserve quota');
check_queue($q->dispatchOne($send, 1700001011) === 'empty' && count($calls) === 1, 'unknown retried or cap ignored');
$q->configureInstallation('disabled_1', 1, false);
$q->consent('disabled_1', $phone, 'admin_123', 'notice-v1', 1700001000);
reject_queue(fn() => $q->enqueue('disabled_1', 'lead_1', $phone, $lead, 1700001001), 'disabled installation accepted');
// Stored data and errors must not expose the synthetic numbers or lead details.
$dump = ''; foreach (['ieb_sms_messages','ieb_sms_consents','ieb_sms_suppression'] as $table) $dump .= json_encode($db->query('SELECT * FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC));
check_queue(strpos($dump, $phone) === false && strpos($dump, 'Synthetic Person') === false && strpos($dump, '+12025550102') === false, 'plaintext PII in database');
echo "PASS: consent, global STOP/START, encrypted queue, idempotency, retry, uncertain outcomes, quota, disabled entitlement\n";
