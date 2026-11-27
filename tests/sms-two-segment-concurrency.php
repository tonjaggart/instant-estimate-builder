<?php
// Real concurrent SQLite claimers; fake provider only.
require __DIR__.'/../sms-relay/queue.php';
function cap_check($v,$m){if(!$v)throw new RuntimeException($m);}
$path=tempnam(getenv('TMPDIR') ?: sys_get_temp_dir(),'sms-attempt-');$key=random_bytes(32);$now=time();$phone='+12025550101';
try {
 $db=new PDO('sqlite:'.$path);$q=new IebSmsQueue($db,$key);$q->install();$q->configureInstallation('test',1,true);$q->consent('test',$phone,'admin','synthetic',$now);
 $lead=['name'=>'李😀','phone'=>'(202) 555-0192','service'=>'Roofing Estimate','low'=>'$14,900','high'=>'$25,800','zip'=>'00005'];foreach(range(1,12) as $i)$q->enqueue('test','event_'.$i,$phone,$lead,$now);unset($q,$db);
 $pids=[];for($i=0;$i<12;$i++){ $pid=pcntl_fork();if($pid===0){try{$db=new PDO('sqlite:'.$path);$q=new IebSmsQueue($db,$key);$result=$q->dispatchOne(fn()=>'unknown',$now);exit(in_array($result,['unknown','capped'],true)?0:2);}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}}cap_check($pid>0,'fork failed');$pids[]=$pid;}
 foreach($pids as $pid){pcntl_waitpid($pid,$status);cap_check(pcntl_wifexited($status)&&pcntl_wexitstatus($status)===0,'child failed');}
 $db=new PDO('sqlite:'.$path);$counts=$db->query('select state,count(*) n from ieb_sms_messages group by state')->fetchAll(PDO::FETCH_KEY_PAIR);cap_check(($counts['unknown']??0)===1&&($counts['capped']??0)===11,'concurrent attempt cap exceeded');
 echo "PASS: 12 concurrent dispatchers, one charged provider attempt\n";
}finally{unset($db);foreach(['','-wal','-shm','-journal'] as $suffix)@unlink($path.$suffix);}
