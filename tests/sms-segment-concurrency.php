<?php
// Synthetic multi-process SQLite test. All data lives in the configured scratch dir.
require __DIR__.'/../sms-relay/queue.php';
if (!function_exists('pcntl_fork')) throw new RuntimeException('pcntl required');
$path=tempnam(getenv('TMPDIR') ?: __DIR__,'ieb-segment-'); $key=random_bytes(32); $now=time();
try {
    $db=new PDO('sqlite:'.$path); $q=new IebSmsQueue($db,$key); $q->install();
    $q->configureInstallation('synthetic',10,true); $q->configureSegmentCap('synthetic',3);
    $phone='+12025550101'; $q->consent('synthetic',$phone,'admin','test',$now);
    $lead=['name'=>'雪','phone'=>'(202) 555-0192','service'=>'Gutter Replacement','low'=>'$960','high'=>'$2,400','zip'=>'61264'];
    for($i=0;$i<4;$i++) $q->enqueue('synthetic','event_'.$i,$phone,$lead,$now);
    unset($q,$db); $children=[];
    for($i=0;$i<4;$i++) {
        $pid=pcntl_fork(); if ($pid===-1) throw new RuntimeException('fork failed');
        if ($pid===0) {
            try { $child=new IebSmsQueue(new PDO('sqlite:'.$path),$key); $result=$child->dispatchOne(function(){usleep(100000); return 'accepted';},$now); exit(in_array($result,['accepted','capped'],true)?0:2); }
            catch(Throwable $e) { fwrite(STDERR,$e->getMessage()."\n"); exit(3); }
        }
        $children[]=$pid;
    }
    foreach($children as $pid) { pcntl_waitpid($pid,$status); if (!pcntl_wifexited($status) || pcntl_wexitstatus($status)!==0) throw new RuntimeException('worker failed'); }
    $db=new PDO('sqlite:'.$path); $states=$db->query('SELECT state,count(*) n FROM ieb_sms_messages GROUP BY state')->fetchAll(PDO::FETCH_KEY_PAIR);
    $used=(int)$db->query("SELECT SUM(s.segments) FROM ieb_sms_messages m JOIN ieb_sms_message_segments s ON s.message_id=m.id WHERE m.state IN ('accepted','unknown','expired_unknown')")->fetchColumn();
    if (($states['accepted']??0)!=1 || ($states['capped']??0)!=3 || $used!==2) throw new RuntimeException('concurrent segment cap violated');
    echo "PASS: four competing workers respect three-segment budget\n";
} finally { @unlink($path); @unlink($path.'-journal'); @unlink($path.'-wal'); @unlink($path.'-shm'); }
