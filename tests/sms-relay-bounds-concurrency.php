<?php
// Real SQLite processes, synthetic data and fake providers only.
require_once __DIR__.'/../sms-relay/queue.php'; require_once __DIR__.'/../sms-relay/verification.php';
function race_check($ok,$message) { if (!$ok) throw new RuntimeException($message); }
function race_children($fn,$n) {
    $pids=[]; for ($i=0;$i<$n;$i++) { $pid=pcntl_fork(); if ($pid===-1) throw new RuntimeException('fork'); if ($pid===0) { try { $fn($i); exit(0); } catch (Throwable $e) { fwrite(STDERR,$e->getMessage()."\n"); exit(1); } } $pids[]=$pid; }
    foreach ($pids as $pid) { pcntl_waitpid($pid,$status); race_check(pcntl_wifexited($status) && pcntl_wexitstatus($status)===0,'child failed'); }
}
$path=tempnam(getenv('TMPDIR') ?: sys_get_temp_dir(),'sms-bounds-'); $key=random_bytes(32); $now=1700000000; $phone='+15550000101'; $lead=['name'=>'A','phone'=>'B','service'=>'C'];
try {
 $db=new PDO('sqlite:'.$path); $q=new IebSmsQueue($db,$key); $q->install(); $q->configureInstallation('install_1',500,true); $q->consent('install_1',$phone,'admin_1','notice-v1',$now);
 for ($i=0;$i<99;$i++) $q->enqueue('install_1','event_'.$i,$phone,$lead,$now);
 unset($q,$db);
 race_children(function($i) use($path,$key,$now,$phone,$lead) { $db=new PDO('sqlite:'.$path); $q=new IebSmsQueue($db,$key); try { $q->enqueue('install_1','race_'.$i,$phone,$lead,$now); } catch (DomainException $e) { if ($e->getMessage()!=='Backlog full') throw $e; } },12);
 $db=new PDO('sqlite:'.$path); race_check((int)$db->query("SELECT COUNT(*) FROM ieb_sms_messages WHERE state='queued'")->fetchColumn()===100,'backlog race exceeded capacity');
 $v=new IebSmsVerification($db,$key); for ($i=0;$i<12;$i++) $v->request('install_1',sprintf('%032x',$i+1),sprintf('+1555000%04d',$i),'admin_1','admin-sms-v1',true,$now);
 $s=$db->prepare('INSERT INTO ieb_sms_verification_budget VALUES (?,?,?)'); for ($i=100;$i<119;$i++) $s->execute(['install_1',sprintf('%032x',$i),gmdate('Y-m',$now)]);
 unset($s,$v,$db);
 race_children(function() use($path,$key,$now) { $db=new PDO('sqlite:'.$path); $v=new IebSmsVerification($db,$key); $result=$v->dispatch(fn()=>'unknown',$now); race_check(in_array($result,['unknown','capped'],true),'unexpected dispatch result'); },12);
 $db=new PDO('sqlite:'.$path); race_check((int)$db->query("SELECT COUNT(*) FROM ieb_sms_verification_budget")->fetchColumn()===20,'verification budget raced');
 race_check((int)$db->query("SELECT COUNT(*) FROM ieb_sms_verifications WHERE state='unknown'")->fetchColumn()===1,'multiple verification claims');
 race_check((int)$db->query("SELECT COUNT(*) FROM ieb_sms_verifications WHERE state='capped'")->fetchColumn()===11,'budget did not fence remaining jobs');
 echo "PASS: concurrent backlog and verification-budget admission\n";
} finally { unset($db); @unlink($path); @unlink($path.'-journal'); @unlink($path.'-wal'); @unlink($path.'-shm'); }
