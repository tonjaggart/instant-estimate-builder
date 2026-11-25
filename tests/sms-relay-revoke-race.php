<?php
// The relay must tombstone an opted-out verification ID even before request arrives.
require __DIR__.'/../sms-relay/auth.php'; require __DIR__.'/../sms-relay/queue.php'; require __DIR__.'/../sms-relay/verification.php'; require __DIR__.'/../sms-relay/api.php';
$db=new PDO('sqlite::memory:'); $crypt=random_bytes(32); $key=bin2hex(random_bytes(32)); $q=new IebSmsQueue($db,$crypt); $q->install(); ieb_auth_install_schema($db); $q->configureInstallation('install_1',5,true); $v=new IebSmsVerification($db,$crypt); $api=new IebRelayApi($db,$q,['install_1'=>$key],$v);
function race_req($api,$key,$path,$data) { $body=json_encode($data); $time=(string)time(); $nonce=bin2hex(random_bytes(16)); return $api->handle('POST',$path,['installation'=>'install_1','timestamp'=>$time,'nonce'=>$nonce,'signature'=>ieb_auth_sign($key,'install_1','POST',$path,$time,$nonce,$body)],$body,time()); }
$id=bin2hex(random_bytes(16)); $phone='+15551230001'; $actor='admin_1';
$r=race_req($api,$key,'/v1/revoke',['phone'=>$phone,'actor'=>$actor,'request_id'=>$id]);
if ($r[0]!==200) { fwrite(STDERR,"FAIL: relay must accept revoked request ID\n"); exit(1); }
$r=race_req($api,$key,'/v1/verification/request',['request_id'=>$id,'phone'=>$phone,'actor'=>$actor,'notice'=>'admin-sms-v2','opt_in'=>true]);
if ($r[0]!==202) throw new RuntimeException('request response must not enumerate suppression');
$count=0; $state=$v->dispatch(function() use (&$count) { $count++; return 'accepted'; },time());
if ($state!=='empty' || $count!==0) throw new RuntimeException('revoked request dispatched verification');
echo "PASS: revocation tombstone precedes racing verification request\n";
