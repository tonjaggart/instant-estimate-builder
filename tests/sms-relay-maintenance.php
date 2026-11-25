<?php
// Exercise the real CLI with an isolated SQLite DB and sending disabled.
require __DIR__.'/../sms-relay/queue.php';
$dir=getenv('TMPDIR'); if (!$dir) throw new RuntimeException('TMPDIR required');
$base=tempnam($dir,'ieb-maint-'); $dbPath=$base.'.sqlite'; $config=$base.'.json';
try {
    $key=random_bytes(32); $db=new PDO('sqlite:'.$dbPath); $q=new IebSmsQueue($db,$key); $q->install();
    $db->exec("INSERT INTO ieb_sms_verifications (installation,request_id,phone_hash,actor,notice,code_hash,ciphertext,state,created_at,expires_at) VALUES ('test','id','hash','actor','notice','code','cipher','queued',1,2)");
    file_put_contents($config,json_encode(['database'=>$dbPath,'encryption_key'=>bin2hex($key),'live_send_enabled'=>false]));
    $cmd='IEB_RELAY_CONFIG='.escapeshellarg($config).' '.escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/../sms-relay/maintenance.php');
    exec($cmd,$output,$status);
    if ($status!==0) throw new RuntimeException('maintenance command failed');
    $row=$db->query('SELECT * FROM ieb_sms_verifications')->fetch(PDO::FETCH_ASSOC);
    if ($row['ciphertext']!=='' || $row['phone_hash']!=='') throw new RuntimeException('maintenance did not clear expired payload');
    echo "PASS: disabled-send CLI maintenance on SQLite\n";
} finally { @unlink($config); @unlink($dbPath); @unlink($base); }
