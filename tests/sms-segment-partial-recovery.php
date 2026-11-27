<?php
// Tests interrupted schema upgrade and forward recovery against disposable SQLite only.
require __DIR__.'/../sms-relay/queue.php';
function check_recovery($condition, $message): void { if (!$condition) throw new RuntimeException($message); }
if (($argv[1] ?? '') === 'migrate-and-interrupt') {
    $q = new IebSmsQueue(new PDO('sqlite:'.$argv[2]), hex2bin($argv[3]));
    $q->migrateSegmentSchema();
    // Simulate interruption after committed schema, before setting the segment cap.
    exit(91);
}
$base=getenv('TMPDIR');
check_recovery($base && str_contains($base,'/.hermes/cache/scratch'), 'private scratch TMPDIR required');
$dir=$base.'/ieb-partial-recovery-'.bin2hex(random_bytes(8));
check_recovery(mkdir($dir,0700), 'scratch creation failed');
try {
    $path=$dir.'/relay.sqlite'; $key=random_bytes(32); $db=new PDO('sqlite:'.$path);
    $q=new IebSmsQueue($db,$key); $q->install();
    $db->exec('DROP TRIGGER ieb_sms_segment_claim_guard');
    $db->exec('DROP TABLE ieb_sms_message_segments');
    $db->exec('DROP TABLE ieb_sms_segment_caps');
    $q->configureInstallation('pilot',100,true);
    $q->consent('pilot','+1'.'202'.'555'.'0101','admin','synthetic-notice',time());
    $db->exec("INSERT INTO ieb_sms_messages (installation,event,phone_hash,payload_hash,ciphertext,state,due_at,quota_month,expires_at) VALUES ('pilot','historical','hash','hash','old-cipher','accepted',1700000000,'2020-01',1700003600)");
    $tables=$db->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    $read=function() use ($db,$tables) { $rows=[]; foreach ($tables as $table) $rows[$table]=$db->query('SELECT * FROM "'.$table.'" ORDER BY rowid')->fetchAll(PDO::FETCH_ASSOC); return $rows; };
    $before=$read();
    $process=proc_open([PHP_BINARY,__FILE__,'migrate-and-interrupt',$path,bin2hex($key)],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    check_recovery(is_resource($process),'child failed to start');
    $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    check_recovery(proc_close($process)===91,'interruption was not reached: '.$out.' '.$err);
    check_recovery($before===$read(),'interrupted migration changed existing record contents');
    check_recovery((int)$db->query("SELECT count(*) FROM sqlite_master WHERE name='ieb_sms_segment_claim_guard'")->fetchColumn()===1,'guard not committed');
    check_recovery((int)$db->query('SELECT count(*) FROM ieb_sms_segment_caps')->fetchColumn()===0,'cap was unexpectedly set');
    $q->migrateSegmentSchema(); $q->configureSegmentCap('pilot',200);
    check_recovery($before===$read(),'recovery changed existing record contents');
    check_recovery($db->query("SELECT monthly_cap FROM ieb_sms_segment_caps WHERE installation='pilot'")->fetchColumn()===200,'segment cap recovery failed');
    check_recovery($db->query("SELECT monthly_cap FROM ieb_sms_installations WHERE id='pilot'")->fetchColumn()===100,'alert cap changed');
    echo "PASS: interrupted committed migration resumes without changing existing records; 100 alert / 200 segment caps\n";
} finally {
    $q=null; $db=null;
    foreach (glob($dir.'/*') ?: [] as $file) unlink($file);
    rmdir($dir);
}
