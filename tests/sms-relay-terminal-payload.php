<?php
require_once __DIR__.'/../sms-relay/queue.php';
function check_terminal($condition,$message) { if (!$condition) throw new RuntimeException($message); }
$db=new PDO('sqlite::memory:'); $q=new IebSmsQueue($db,random_bytes(32)); $q->install();
$q->configureInstallation('install_123',2,true); $phone='+15550000101'; $q->consent('install_123',$phone,'admin_1','notice-v1',1700000000);
$lead=['name'=>'Synthetic','phone'=>'Synthetic','service'=>'Synthetic'];
foreach (['accepted','unknown','capped'] as $i=>$expected) {
    $q->enqueue('install_123','event_'.$i,$phone,$lead,1700000000+$i);
    $result=$q->dispatchOne(fn()=>$expected,1700000000+$i);
    check_terminal($result===$expected,'unexpected result: '.$result);
    $row=$db->query('SELECT state,ciphertext,payload_hash FROM ieb_sms_messages WHERE event=\'event_'.$i.'\'')->fetch(PDO::FETCH_ASSOC);
    check_terminal($row['ciphertext']==='', $expected.' retained ciphertext');
    check_terminal(strlen($row['payload_hash'])===64,'idempotency digest missing');
    check_terminal($q->enqueue('install_123','event_'.$i,$phone,$lead,1700001000)==='duplicate','idempotency lost');
}
echo "PASS: terminal payloads erased without losing event idempotency\n";
