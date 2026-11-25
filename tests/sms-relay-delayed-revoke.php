<?php
// A retry delayed across re-enrollment must not revoke the newer generation.
require __DIR__.'/../sms-relay/queue.php';
require __DIR__.'/../sms-relay/verification.php';
function demand($ok,$msg) { if (!$ok) throw new RuntimeException($msg); }
$db=new PDO('sqlite::memory:'); $key=random_bytes(32); $q=new IebSmsQueue($db,$key); $q->install(); $q->configureInstallation('install_a',10,true); $v=new IebSmsVerification($db,$key);
$now=time(); $phone='+12025550101'; $old=bin2hex(random_bytes(16)); $new=bin2hex(random_bytes(16));
$activate=function($id) use($v,$phone,$now) {
    $v->request('install_a',$id,$phone,'admin_1','admin-sms-v2',true,$now);
    $message=''; demand($v->dispatch(function($to,$body) use (&$message) { $message=$body; return 'accepted'; },$now)==='accepted','verification');
    preg_match('/\b([0-9]{6})\b/',$message,$match); demand($v->confirm('install_a',$id,$match[1],$now),'confirm');
};
$activate($old); $q->revoke('install_a',$phone,'admin_1',$now,$old); $activate($new);
$lead=['name'=>'Test','phone'=>'2025550199','service'=>'Gutters'];
demand($q->enqueue('install_a','new_event',$phone,$lead,$now,$new)==='queued','new generation queued');
$q->revoke('install_a',$phone,'admin_1',$now,$old);
$count=0; demand($q->dispatchOne(function() use (&$count) { ++$count; return 'accepted'; },$now)==='accepted' && $count===1,'delayed duplicate revoke affected newer generation');
echo "PASS: delayed revoke cannot suppress later consent generation\n";
