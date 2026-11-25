<?php
// An installation's signed assertion is not proof that the phone owner opted in.
require __DIR__.'/../sms-relay/auth.php';
require __DIR__.'/../sms-relay/queue.php';
require __DIR__.'/../sms-relay/api.php';
$db=new PDO('sqlite::memory:');
$q=new IebSmsQueue($db,random_bytes(32)); $q->install(); ieb_auth_install_schema($db);
$id='install_123'; $secret=bin2hex(random_bytes(32)); $q->configureInstallation($id,100,true);
$api=new IebRelayApi($db,$q,[$id=>$secret]); $now=time(); $body=json_encode(['phone'=>'+12025550101','actor'=>'admin_1','notice'=>'notice-v1']);
$nonce=bin2hex(random_bytes(16)); $ts=(string)$now;
$headers=['installation'=>$id,'timestamp'=>$ts,'nonce'=>$nonce,'signature'=>ieb_auth_sign($secret,$id,'POST','/v1/consent',$ts,$nonce,$body)];
[$status]=$api->handle('POST','/v1/consent',$headers,$body,$now);
if ($status===200) throw new RuntimeException('A signed assertion must not activate unverified consent');
try { $q->enqueue($id,'event_1','+12025550101',['name'=>'Test','phone'=>'5550100','service'=>'Test'],$now); throw new RuntimeException('Unverified recipient was allowed'); }
catch (DomainException $e) {}
echo "PASS: signed unverified consent cannot authorize notifications\n";
