<?php
// Actual previous relay worker against the migrated disposable SQLite DB.
require __DIR__.'/../sms-relay/queue.php';
$base=getenv('TMPDIR'); if (!$base || !str_contains($base,'/.hermes/cache/scratch')) throw new RuntimeException('private scratch TMPDIR required');
$dir=$base.'/ieb-mixed-'.bin2hex(random_bytes(8)); if (!mkdir($dir,0700)) throw new RuntimeException('scratch create failed');
try {
    foreach (['queue.php','lead-format.php'] as $file) {
        $process=proc_open(['git','show','8e17d2e:sms-relay/'.$file],[1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__));
        $source=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($process)!==0 || !$source) throw new RuntimeException('cannot read old version '.$err);
        file_put_contents($dir.'/'.$file,$source);
    }
    file_put_contents($dir.'/old-worker.php', <<<'PHP'
<?php
require __DIR__.'/queue.php';
$q=new IebSmsQueue(new PDO('sqlite:'.$argv[1]),hex2bin($argv[2]));
try { $q->dispatchOne(function(){echo "PROVIDER_CALLED";return 'accepted';},(int)$argv[3]); exit(3); }
catch (PDOException $e) { if (strpos($e->getMessage(),'segment reservation required')===false) exit(4); echo 'OLD_WORKER_FENCED'; }
PHP);
    $path=$dir.'/queue.sqlite'; $key=random_bytes(32); $now=time(); $db=new PDO('sqlite:'.$path); $q=new IebSmsQueue($db,$key); $q->install(); $q->configureInstallation('pilot',100,true); $q->configureSegmentCap('pilot',200);
    $phone='+12025550101'; $q->consent('pilot',$phone,'admin','notice',$now);
    $lead=['name'=>'雪','phone'=>'(202) 555-0192','service'=>'Gutter Replacement','low'=>'$960','high'=>'$2,400','zip'=>'61264']; $q->enqueue('pilot','event',$phone,$lead,$now);
    $proc=proc_open([PHP_BINARY,$dir.'/old-worker.php',$path,bin2hex($key),(string)$now],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $status=proc_close($proc);
    if ($status!==0 || $out!=='OLD_WORKER_FENCED') throw new RuntimeException('old worker not fenced: '.$out.' '.$err);
    if ($db->query('SELECT state FROM ieb_sms_messages')->fetchColumn()!=='queued') throw new RuntimeException('old worker changed event');
    $calls=0; if ($q->dispatchOne(function()use(&$calls){$calls++;return 'accepted';},$now)!=='accepted' || $calls!==1) throw new RuntimeException('new worker could not dispatch');
    echo "PASS: actual previous worker fenced by migrated database; current worker reserves and dispatches fake SMS\n";
} finally { foreach (glob($dir.'/*') ?: [] as $file) unlink($file); rmdir($dir); }
