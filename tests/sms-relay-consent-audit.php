<?php
require_once __DIR__.'/../sms-relay/queue.php';
function expect_audit($ok, $message) { if (!$ok) throw new RuntimeException($message); }
$db=new PDO('sqlite::memory:'); $q=new IebSmsQueue($db,random_bytes(32)); $q->install();
$q->configureInstallation('install_123',5,true);
$q->configureInstallation('install_456',5,true);
$phone='+12025550101'; $lead=['name'=>'Example','phone'=>'+12025550102','service'=>'Example'];
$q->consent('install_123',$phone,'admin_a','notice-v1',1700000000);
$q->consent('install_123',$phone,'admin_a','notice-v2',1700000001);
$q->consent('install_456',$phone,'admin_b','notice-v2',1700000001);
$q->revoke('install_123',$phone,'admin_a',1700000002);
try { $q->enqueue('install_123','lead_a',$phone,$lead,1700000003); throw new RuntimeException('Revoked consent accepted'); } catch (DomainException $e) {}
expect_audit($q->enqueue('install_456','lead_a',$phone,$lead,1700000003)==='queued','Cross-install revoke');
$q->stop($phone,1700000004);
expect_audit($q->dispatchOne(fn() => 'accepted',1700000005)==='suppressed','Global STOP');
$rows=$db->query('SELECT installation,actor,notice,action,occurred_at FROM ieb_sms_consent_audit ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
expect_audit(count($rows)===5, 'Consent history lost');
expect_audit($rows[0]['notice']==='notice-v1' && $rows[1]['notice']==='notice-v2' && $rows[3]['action']==='revoke' && $rows[4]['action']==='stop', 'Consent audit wrong');
echo "PASS: retained consent history, scoped revoke, global STOP\n";
