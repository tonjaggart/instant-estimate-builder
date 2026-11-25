<?php
if (!is_file(__DIR__.'/../sms-relay/api.php')) throw new RuntimeException('API implementation missing');
require __DIR__.'/../sms-relay/auth.php'; require __DIR__.'/../sms-relay/queue.php'; require __DIR__.'/../sms-relay/api.php';
function ck($condition,$msg) { if (!$condition) throw new RuntimeException($msg); }
$db=new PDO('sqlite::memory:'); $q=new IebSmsQueue($db,random_bytes(32)); $q->install(); ieb_auth_install_schema($db); $id='install_123'; $key=ieb_auth_generate_key(); $q->configureInstallation($id,5,true); $api=new IebRelayApi($db,$q,[$id=>$key]);
$phone='+12025550101'; $lead=['name'=>'Synthetic Name','phone'=>'+12025550102','service'=>'Test'];
function request($api,$key,$id,$path,$body,$nonce=null) { $body=json_encode($body); $timestamp=(string)time(); $nonce=$nonce??bin2hex(random_bytes(16)); return $api->handle('POST',$path,['installation'=>$id,'timestamp'=>$timestamp,'nonce'=>$nonce,'signature'=>ieb_auth_sign($key,$id,'POST',$path,$timestamp,$nonce,$body)],$body,time()); }
$event=['event'=>'event_1','phone'=>$phone,'lead'=>$lead,'request_id'=>str_repeat('a',32)]; ck(request($api,$key,$id,'/v1/events',$event)[0]===409,'unconsented enqueue');
ck(request($api,$key,$id,'/v1/consent',['phone'=>$phone,'actor'=>'admin_1','notice'=>'notice-v1'])[0]===403,'unverified consent must fail');
// Seed internal state solely to test the independently implemented queue and revoke.
$q->consent($id,$phone,'synthetic_fixture','notice-v1',time());
$event['request_id']=$db->query('SELECT epoch FROM ieb_sms_consent_epochs')->fetchColumn();
$nonce=bin2hex(random_bytes(16)); ck(request($api,$key,$id,'/v1/events',$event,$nonce)===[200,['result'=>'queued']],'event'); ck(request($api,$key,$id,'/v1/events',$event,$nonce)[0]===401,'replay'); ck(request($api,$key,$id,'/v1/events',$event)===[200,['result'=>'duplicate']],'idempotency');
$event['lead']['name']='Changed'; ck(request($api,$key,$id,'/v1/events',$event)[0]===409,'conflict');
ck(request($api,ieb_auth_generate_key(),$id,'/v1/events',$event)[0]===401,'bad signature');
ck(request($api,$key,$id,'/v1/revoke',['phone'=>$phone,'actor'=>'admin_1'])[0]===200,'revoke');
$event['event']='event_2'; ck(request($api,$key,$id,'/v1/events',$event)[0]===409,'revocation');
$q->stop($phone,time()); ck(request($api,$key,$id,'/v1/consent',['phone'=>$phone,'actor'=>'admin_1','notice'=>'notice-v1'])[0]===403,'STOP must not report consent as recorded');
echo "PASS: API auth, unverified consent denial, replay, idempotency, conflict and revocation with seeded fixture\n";
