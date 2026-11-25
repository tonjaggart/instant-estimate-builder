<?php
require_once __DIR__.'/../sms-relay/queue.php';
$dir=getenv('RACE_DIR'); $db=new PDO('sqlite:'.$dir.'/db'); $q=new IebSmsQueue($db,hex2bin(getenv('RACE_KEY')));
$p='+12025550101'; $lead=['name'=>'Test','phone'=>'+12025550102','service'=>'Example'];
switch ($argv[1]) {
case 'setup': $q->install(); $q->configureInstallation('install_123',10,true); $q->consent('install_123',$p,'admin_1','notice-v1',time()); $q->enqueue('install_123','event_1',$p,$lead,time()); $q->enqueue('install_123','event_2',$p,$lead,time()); break;
case 'send': echo $q->dispatchOne(function() use ($dir) { touch($dir.'/entered'); for ($i=0;$i<500 && !file_exists($dir.'/release');$i++) usleep(10000); return 'accepted'; },time()); break;
case 'stop': $q->stop($p,time()); echo 'stopped'; break;
}
