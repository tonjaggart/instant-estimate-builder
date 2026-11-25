<?php
// Synthetic fixtures only; the provider is injected and never uses a network.
require __DIR__.'/../sms-relay/auth.php'; require __DIR__.'/../sms-relay/queue.php'; require __DIR__.'/../sms-relay/verification.php'; require __DIR__.'/../sms-relay/api.php';
function ckverify($ok,$why) { if (!$ok) throw new RuntimeException($why); }
$db=new PDO('sqlite::memory:'); $crypt=random_bytes(32); $q=new IebSmsQueue($db,$crypt); $v=new IebSmsVerification($db,$crypt); $q->install(); ieb_auth_install_schema($db);
$a='install_a'; $b='install_b'; $ka=ieb_auth_generate_key(); $kb=ieb_auth_generate_key(); $q->configureInstallation($a,10,true); $q->configureInstallation($b,10,true); $api=new IebRelayApi($db,$q,[$a=>$ka,$b=>$kb],$v); $now=time();
function vreq($api,$key,$id,$path,$data,$now) { $body=json_encode($data); $nonce=bin2hex(random_bytes(16)); $ts=(string)$now; return $api->handle('POST',$path,['installation'=>$id,'timestamp'=>$ts,'nonce'=>$nonce,'signature'=>ieb_auth_sign($key,$id,'POST',$path,$ts,$nonce,$body)],$body,$now); }
$phone='+15550000101'; $request=['request_id'=>bin2hex(random_bytes(16)),'phone'=>$phone,'actor'=>'admin_1','notice'=>'admin-sms-v1','opt_in'=>true];
$r=vreq($api,$ka,$a,'/v1/verification/request',$request,$now); ckverify($r===[202,['result'=>'requested']],'request should be accepted');
ckverify(vreq($api,$ka,$a,'/v1/verification/request',$request,$now)===$r,'request must be idempotent');
$event=['event'=>'event_1','phone'=>$phone,'lead'=>['name'=>'Synthetic','phone'=>'+15550000102','service'=>'Test']]; ckverify(vreq($api,$ka,$a,'/v1/events',$event,$now)[0]===409,'not verified');
$messages=[]; $send=function($to,$body) use (&$messages) { $messages[]=[$to,$body]; return 'accepted'; }; ckverify($v->dispatch($send,$now)==='accepted','verification dispatch'); ckverify(count($messages)===1 && $messages[0][0]===$phone && strpos($messages[0][1],'Taggart Media Group')!==false,'sender/recipient'); ckverify(preg_match('/\b([0-9]{6})\b/',$messages[0][1],$match)===1,'code'); $code=$match[1];
$confirm=['request_id'=>$request['request_id'],'code'=>$code]; ckverify(vreq($api,$kb,$b,'/v1/verification/confirm',$confirm,$now)[0]===409,'cross installation'); ckverify(vreq($api,$ka,$a,'/v1/verification/confirm',['request_id'=>$request['request_id'],'code'=>'abcdef'],$now)[0]===400,'bad code format');
$bad=$code==='000000'?'000001':'000000'; ckverify(vreq($api,$ka,$a,'/v1/verification/confirm',['request_id'=>$request['request_id'],'code'=>$bad],$now)[0]===409,'wrong code'); ckverify(vreq($api,$ka,$a,'/v1/verification/confirm',$confirm,$now)===[200,['result'=>'verified']],'correct code'); ckverify(vreq($api,$ka,$a,'/v1/verification/confirm',$confirm,$now)[0]===409,'replay'); ckverify(vreq($api,$ka,$a,'/v1/events',$event,$now)[0]===200,'verified enqueue'); ckverify($v->dispatch($send,$now)==='empty','duplicate send');
$dump=json_encode($db->query('SELECT * FROM ieb_sms_verifications')->fetchAll(PDO::FETCH_ASSOC)); ckverify(strpos($dump,$phone)===false && strpos($dump,$code)===false,'plaintext verification data');
$q->stop($phone,$now+1); $request['request_id']=bin2hex(random_bytes(16)); ckverify(vreq($api,$ka,$a,'/v1/verification/request',$request,$now+2)[0]===202,'suppression must not be enumerated'); ckverify($v->dispatch($send,$now+2)==='empty','STOP prevents verification'); ckverify(vreq($api,$ka,$a,'/v1/verification/confirm',['request_id'=>$request['request_id'],'code'=>$code],$now+2)[0]===409,'STOP prevents activation');
$fresh=function($number) use ($request) { return array_merge($request,['request_id'=>bin2hex(random_bytes(16)),'phone'=>$number]); };
$r2=$fresh('+15550000104'); $v->request($a,$r2['request_id'],$r2['phone'],'admin_1','admin-sms-v1',true,$now); $v->dispatch($send,$now); preg_match('/\b([0-9]{6})\b/',end($messages)[1],$m); $c2=$m[1];
for ($i=0;$i<5;$i++) ckverify(!$v->confirm($a,$r2['request_id'],$c2==='000000'?'000001':'000000',$now),'wrong code accepted');
ckverify(!$v->confirm($a,$r2['request_id'],$c2,$now),'attempt limit bypassed');
$r3=$fresh('+15550000105'); $v->request($a,$r3['request_id'],$r3['phone'],'admin_1','admin-sms-v1',true,$now); $v->dispatch($send,$now); preg_match('/\b([0-9]{6})\b/',end($messages)[1],$m); ckverify(!$v->confirm($a,$r3['request_id'],$m[1],$now+600),'expired code accepted');
$r4=$fresh('+15550000106'); $v->request($a,$r4['request_id'],$r4['phone'],'admin_1','admin-sms-v1',true,$now); ckverify($v->dispatch(function() { throw new RuntimeException('secret provider exception'); },$now)==='unknown','uncertain dispatch'); ckverify($v->dispatch($send,$now)==='empty','uncertain dispatch retried');
$r5=$fresh('+15550000107'); $v->request($a,$r5['request_id'],$r5['phone'],'admin_1','admin-sms-v1',true,$now); $v->dispatch($send,$now); preg_match('/\b([0-9]{6})\b/',end($messages)[1],$m); $q->revoke($a,$r5['phone'],'admin_1',$now); ckverify(!$v->confirm($a,$r5['request_id'],$m[1],$now),'revoked challenge accepted');
$r6=$fresh('+15550000108'); $v->request($a,$r6['request_id'],$r6['phone'],'admin_1','admin-sms-v1',true,$now); $q->stop($r6['phone'],$now); $q->start($r6['phone'],$now+1); ckverify($v->dispatch($send,$now+1)==='empty','STOP challenge survived START');
$q->configureInstallation('install_c',10,true);
for ($i=0;$i<21;$i++) $v->request('install_c',bin2hex(random_bytes(16)),sprintf('+15550002%03d',$i),'admin_1','admin-sms-v1',true,$now);
$counts=$db->query("SELECT state,count(*) AS n FROM ieb_sms_verifications WHERE installation='install_c' GROUP BY state")->fetchAll(PDO::FETCH_KEY_PAIR);
ckverify((int)$counts['queued']===20 && (int)$counts['blocked']===1,'installation rate limit');
try { $v->request($a,$r6['request_id'],'+15550000109','admin_1','admin-sms-v1',true,$now); throw new RuntimeException('changed idempotent request accepted'); } catch (DomainException $e) {}
echo "PASS: verification opt-in, isolation, idempotency, STOP and secrecy\n";
