<?php
require __DIR__.'/../sms-relay/auth.php'; require __DIR__.'/../sms-relay/queue.php'; require __DIR__.'/../sms-relay/verification.php'; require __DIR__.'/../sms-relay/api.php';
$db=new PDO('sqlite::memory:'); $q=new IebSmsQueue($db,random_bytes(32)); $q->install(); ieb_auth_install_schema($db); $q->configureInstallation('test_install',2,true); $phone='+12025550101'; $q->consent('test_install',$phone,'admin','test',time()); $epoch=$db->query('SELECT epoch FROM ieb_sms_consent_epochs')->fetchColumn(); $key=str_repeat('a',64);
$lead=['name'=>'Jane','phone'=>'2025550192','service'=>'Gutters','low'=>'$1,000','high'=>'$2,000','zip'=>'61264'];
$payload=json_encode(['event'=>'event_1','phone'=>$phone,'request_id'=>$epoch,'lead'=>$lead]);
function request_exact($api,$payload,$key) { $ts=(string)time(); $nonce=bin2hex(random_bytes(16)); return $api->handle('POST','/v1/events',['installation'=>'test_install','timestamp'=>$ts,'nonce'=>$nonce,'signature'=>ieb_auth_sign($key,'test_install','POST','/v1/events',$ts,$nonce,$payload)],$payload,(int)$ts); }
if (request_exact(new IebRelayApi($db,$q,['test_install'=>$key]),$payload,$key)[0]!==400) throw new RuntimeException('default API gate failed');
if (request_exact(new IebRelayApi($db,$q,['test_install'=>$key],null,['other_install']),$payload,$key)[0]!==400) throw new RuntimeException('installation whitelist failed');
if (request_exact(new IebRelayApi($db,$q,['test_install'=>$key],null,['test_install']),$payload,$key)[0]!==200) throw new RuntimeException('allowlisted API rejected');
echo "PASS: exact event is rejected by default and restricted to explicit installations\n";
