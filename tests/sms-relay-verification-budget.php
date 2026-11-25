<?php
// Offline fake clock/provider; all recipients are synthetic.
require_once __DIR__.'/../sms-relay/queue.php'; require_once __DIR__.'/../sms-relay/verification.php';
function budget_check($ok,$why) { if (!$ok) throw new RuntimeException($why); }
$db=new PDO('sqlite::memory:'); $key=random_bytes(32); $q=new IebSmsQueue($db,$key); $q->install(); $v=new IebSmsVerification($db,$key);
$q->configureInstallation('install_1',100,true); $now=1700000000; $calls=0;
for ($i=0;$i<21;$i++) {
    $t=$now+$i*3601; $phone=sprintf('+1555000%04d',$i); $id=sprintf('%032x',$i+1);
    $v->request('install_1',$id,$phone,'admin_1','admin-sms-v1',true,$t);
    $outcome=$v->dispatch(function() use (&$calls) { ++$calls; return 'unknown'; },$t);
    if ($i<20) budget_check($outcome==='unknown','reservation failed');
    else budget_check($outcome==='capped','monthly verification budget not enforced');
}
budget_check($calls===20,'budget exceeded provider invocations');
echo "PASS: verification unknown attempts consume monthly budget\n";
