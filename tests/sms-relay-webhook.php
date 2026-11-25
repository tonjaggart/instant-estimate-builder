<?php
if (!is_file(__DIR__.'/../sms-relay/webhook.php')) throw new RuntimeException('Webhook implementation missing');
require __DIR__.'/../sms-relay/vendor/autoload.php';
require __DIR__.'/../sms-relay/queue.php';
require __DIR__.'/../sms-relay/webhook.php';
function ok($condition,$message) { if (!$condition) throw new RuntimeException($message); }
$db=new PDO('sqlite::memory:'); $q=new IebSmsQueue($db,random_bytes(32)); $q->install();
$phone='+12025550101'; $url='https://relay.example.test/v1/twilio'; $token='synthetic-token';
foreach (['install_a','install_b'] as $id) { $q->configureInstallation($id,3,true); $q->consent($id,$phone,'admin_1','notice-v1',time()); }
$body=['From'=>$phone,'To'=>'+12025550100','Body'=>'STOP','MessageSid'=>'SM'.str_repeat('1',32),'AccountSid'=>'AC'.str_repeat('2',32)];
$hook=new IebTwilioWebhook($q,$url,$token,$body['AccountSid'],$body['To']);
$sig=(new Twilio\Security\RequestValidator($token))->computeSignature($url,$body);
ok($hook->handle($body,'bad')[0]===403,'forged signature');
$tampered=$body; $tampered['From']='+12025550103'; ok($hook->handle($tampered,$sig)[0]===403,'tampered request');
ok($hook->handle($body,$sig)[0]===200,'valid STOP');
ok($hook->handle($body,$sig)[0]===200,'duplicate STOP');
ok((int)$db->query("SELECT COUNT(*) FROM ieb_sms_consent_audit WHERE action='stop'")->fetchColumn()===1,'duplicate STOP audit');
foreach (['install_a','install_b'] as $id) {
 try { $q->enqueue($id,'event_1',$phone,['name'=>'Example','phone'=>'+12025550102','service'=>'Roof'],time()); throw new RuntimeException('STOP failed across installations'); } catch (DomainException $e) {}
}
$help=$body; $help['Body']='HELP'; $help['MessageSid']='SM'.str_repeat('3',32); $sig=(new Twilio\Security\RequestValidator($token))->computeSignature($url,$help); $res=$hook->handle($help,$sig); ok($res[0]===200 && strpos($res[1],'support@taggartmediagroup.com')!==false,'HELP response');
$help['OptOutType']='HELP'; $sig=(new Twilio\Security\RequestValidator($token))->computeSignature($url,$help); ok(strpos($hook->handle($help,$sig)[1],'support@')===false,'advanced opt-out duplicate response');
$start=$body; $start['Body']='START'; $start['OptOutType']='START'; $start['MessageSid']='SM'.str_repeat('4',32); $sig=(new Twilio\Security\RequestValidator($token))->computeSignature($url,$start); ok($hook->handle($start,$sig)[0]===200,'START response');
$q->consent('install_a',$phone,'admin_1','notice-v1',time());
try { $q->enqueue('install_a','event_2',$phone,['name'=>'Example','phone'=>'+12025550102','service'=>'Roof'],time()); throw new RuntimeException('START silently removed STOP'); } catch (DomainException $e) {}
echo "PASS: signed webhook tamper, durable STOP replay, cross-installation suppression and HELP\n";
