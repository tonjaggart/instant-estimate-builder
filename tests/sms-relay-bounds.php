<?php
// Offline synthetic fixtures: clock supplied explicitly, never contacts a provider.
require_once __DIR__.'/../sms-relay/queue.php';
function bounds_check($value,$message) { if (!$value) throw new RuntimeException($message); }
$db=new PDO('sqlite::memory:'); $q=new IebSmsQueue($db,random_bytes(32)); $q->install();
$q->configureInstallation('install_1',200,true);
$phone='+15550000101'; $lead=['name'=>'A','phone'=>'B','service'=>'C']; $now=1700000000;
$q->consent('install_1',$phone,'admin_1','notice-v1',$now);
for ($i=0;$i<100;$i++) bounds_check($q->enqueue('install_1','event_'.$i,$phone,$lead,$now)==='queued','admission');
try { $q->enqueue('install_1','over_limit',$phone,$lead,$now); throw new RuntimeException('backlog exceeded limit'); } catch (DomainException $e) { bounds_check($e->getMessage()==='Backlog full','unexpected rejection'); }
bounds_check($q->enqueue('install_1','event_0',$phone,$lead,$now)==='duplicate','duplicate blocked by backlog');
$calls=0;
for ($i=0;$i<100;$i++) bounds_check($q->dispatchOne(function() use (&$calls) { ++$calls; return 'accepted'; },$now+3600)==='expired','expired message was not fenced');
bounds_check($calls===0,'expired message reached provider');
bounds_check((int)$db->query("SELECT COUNT(*) FROM ieb_sms_messages WHERE ciphertext<>''")->fetchColumn()===0,'expired ciphertext retained');
bounds_check($q->enqueue('install_1','event_0',$phone,$lead,$now+3600)==='duplicate','idempotency lost');
bounds_check($q->enqueue('install_1','new_event',$phone,$lead,$now+3600)==='queued','expired backlog did not release capacity');
echo "PASS: bounded admission, expiry and idempotency\n";
