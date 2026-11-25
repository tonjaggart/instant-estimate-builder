<?php
require __DIR__.'/../sms-relay/queue.php'; require __DIR__.'/../sms-relay/verification.php';
$dir=getenv('RACE_DIR'); $key=hex2bin(getenv('RACE_KEY')); $db=new PDO('sqlite:'.$dir.'/db'); $q=new IebSmsQueue($db,$key); $v=new IebSmsVerification($db,$key); $p='+15550000101';
switch ($argv[1]) {
case 'setup': $q->install(); $q->configureInstallation('install_123',10,true); $v->request('install_123',str_repeat('a',32),$p,'admin_1','admin-sms-v1',true,time()); break;
case 'send': echo $v->dispatch(function() use ($dir) { touch($dir.'/entered'); for ($i=0;$i<500 && !file_exists($dir.'/release');$i++) usleep(10000); return 'accepted'; },time()); break;
case 'stop': $q->stop($p,time()); echo 'stopped'; break;
}
