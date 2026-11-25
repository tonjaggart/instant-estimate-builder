<?php
// Offline subprocess worker for the concurrency/crash test.
require_once __DIR__.'/../sms-relay/queue.php';
$db=new PDO('sqlite:'.getenv('IEB_TEST_DB'));
$q=new IebSmsQueue($db,hex2bin(getenv('IEB_TEST_KEY')));
$mode=$argv[1];
if ($mode==='setup') {
    $q->install(); $q->configureInstallation('install_123',2,true);
    $q->consent('install_123','+12025550101','admin_1','notice-v1',1700000000);
    for ($i=0;$i<12;$i++) $q->enqueue('install_123','event_'.$i,'+12025550101',['name'=>'Example','phone'=>'+12025550102','service'=>'Example'],1700000001);
} elseif ($mode==='send') {
    echo $q->dispatchOne(fn()=>'accepted',getenv('IEB_TEST_NOW') ? (int)getenv('IEB_TEST_NOW') : 1700000002);
} elseif ($mode==='crash') {
    $q->dispatchOne(function() { exit(77); },1700000002);
} else { exit(2); }
