<?php
// Offline only: fake provider results; no network or real SMS.
require __DIR__.'/../sms-relay/queue.php';
require __DIR__.'/../sms-relay/delivery.php';
function ok($v,$m) { if (!$v) throw new RuntimeException($m); }
$db=new PDO('sqlite::memory:'); $q=new IebSmsQueue($db,random_bytes(32)); $q->install();
$now=1700000000; $phone='+12025550101'; $sid='SM'.str_repeat('a',32);
$q->configureInstallation('install_1',10,true); $q->consent('install_1',$phone,'admin','notice',$now);
$lead=['name'=>'Synthetic','phone'=>'+12025550102','service'=>'Test'];
$q->enqueue('install_1','event_1',$phone,$lead,$now);
ok($q->dispatchOne(fn()=>['state'=>'accepted','sid'=>$sid],$now)==='accepted','provider accepted');
$row=$db->query('SELECT * FROM ieb_sms_delivery')->fetch(PDO::FETCH_ASSOC);
ok($row && $row['sid']===$sid && $row['status']==='accepted','SID captured privately');
$d=new IebSmsDelivery($db);
ok($d->poll(fn($s)=>'sent',$now+300)===1,'sent polled');
ok($db->query('SELECT status FROM ieb_sms_delivery')->fetchColumn()==='sent','sent not delivered');
ok($d->poll(fn($s)=>'delivered',$now+600)===1,'delivered polled');
ok($db->query('SELECT status FROM ieb_sms_delivery')->fetchColumn()==='delivered','delivered persisted');
ok($d->poll(fn($s)=>throw new Exception('must not poll terminal'),$now+900)===0,'terminal not polled again');
$q->enqueue('install_1','event_2',$phone,$lead,$now+1);
$q->dispatchOne(fn()=>['state'=>'accepted','sid'=>'SM'.str_repeat('b',32)],$now+1);
$d->poll(fn($s)=>throw new Exception('sensitive provider error'),$now+301);
$r=$db->query("SELECT * FROM ieb_sms_delivery WHERE status<>'delivered'")->fetch(PDO::FETCH_ASSOC);
ok((int)$r['lookup_failures']===1,'lookup failure recorded');
$d->poll(fn($s)=>'undelivered',$now+601);
ok($db->query("SELECT COUNT(*) FROM ieb_sms_delivery WHERE status='undelivered'")->fetchColumn()==1,'failure persisted');
$q->enqueue('install_1','event_3',$phone,$lead,$now+2);
ok($q->dispatchOne(fn()=>['state'=>'accepted','sid'=>'invalid'],$now+2)==='unknown','invalid sid unknown');
ok($q->dispatchOne(fn()=>throw new Exception('must not resend'),$now+999)==='empty','unknown not resent');
$q->enqueue('install_1','event_4',$phone,$lead,$now+3); $q->stop($phone,$now+4);
ok($q->dispatchOne(fn()=>throw new Exception('must not send after STOP'),$now+4)==='empty','STOP blocks sends');
$insert=$db->prepare("INSERT INTO ieb_sms_delivery(message_id,sid,status,created_at,next_poll) VALUES(?,?,'accepted',?,?)");
for ($i=100;$i<125;$i++) $insert->execute([$i,'SM'.str_pad(dechex($i),32,'0',STR_PAD_LEFT),$now,$now]);
ok($d->poll(fn()=> 'queued',$now+1000)===20,'batch bounded');
ok($d->poll(fn()=> 'queued',$now+86400)===0,'stale polling stops');
echo "PASS fake delivery status, failure, no retry, STOP\n";
