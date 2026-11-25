<?php
require_once __DIR__.'/../sms-relay/queue.php';
$db=new PDO('sqlite::memory:'); $q=new IebSmsQueue($db,random_bytes(32)); $q->install();
$p='+12025550101'; $q->configureInstallation('install_123',1,true);
$q->stop($p,1700000002); $q->start($p,1700000001);
$q->consent('install_123',$p,'admin_1','notice-v1',1700000003);
try { $q->enqueue('install_123','event_1',$p,['name'=>'A','phone'=>'+12025550102','service'=>'B'],1700000004); throw new RuntimeException('Older START bypassed STOP'); }
catch (DomainException $e) {}
$q->start($p,1700000002); $q->consent('install_123',$p,'admin_1','notice-v1',1700000004);
try { $q->enqueue('install_123','event_2',$p,['name'=>'A','phone'=>'+12025550102','service'=>'B'],1700000005); throw new RuntimeException('Tied START bypassed STOP'); }
catch (DomainException $e) {}
echo "PASS: stale or tied START does not undo STOP\n";
