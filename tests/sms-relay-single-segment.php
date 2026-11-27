<?php
// Synthetic offline recipient and provider; no network calls.
require_once __DIR__.'/../sms-relay/queue.php';
function check_segment($v,$label) { if (!$v) throw new RuntimeException($label); }
function denied_segment($f,$label) { try { $f(); } catch (DomainException $e) { return; } throw new RuntimeException($label); }
$db=new PDO('sqlite::memory:'); $key=random_bytes(32); $q=new IebSmsQueue($db,$key); $q->install();
$q->configureInstallation('install_123',100,true);
$phone='+15550000101'; $q->consent('install_123',$phone,'admin_1','notice-v1',1700000000);
$lead=['name'=>'A','phone'=>'B','service'=>'C'];
$base=ieb_sms_message_text(['name'=>'A','phone'=>'B','service'=>'']);
$gsm=160-strlen($base)-substr_count($base,'|'); // Each vertical bar costs an extra GSM septet.
$lead['service']=str_repeat('a',$gsm); $q->enqueue('install_123','gsm_160',$phone,$lead,1700000000);
$lead['service']=str_repeat('a',$gsm+1); denied_segment(fn()=>$q->enqueue('install_123','gsm_161',$phone,$lead,1700000000),'161 GSM septets accepted');
$lead['service']=str_repeat('^',intdiv($gsm,2)); $q->enqueue('install_123','extension_ok',$phone,$lead,1700000000);
$lead['service']=str_repeat('^',intdiv($gsm,2)+1); denied_segment(fn()=>$q->enqueue('install_123','extension_over',$phone,$lead,1700000000),'GSM escape cost ignored');
$units=70-strlen($base);
$lead['service']='漢'.str_repeat('a',$units-1); $q->enqueue('install_123','ucs_70',$phone,$lead,1700000000);
$lead['service']='漢'.str_repeat('a',$units); denied_segment(fn()=>$q->enqueue('install_123','ucs_71',$phone,$lead,1700000000),'71 UCS-2 units accepted');
$lead['service']='😀'.str_repeat('a',$units-1); denied_segment(fn()=>$q->enqueue('install_123','supplementary_over',$phone,$lead,1700000000),'surrogate pair cost ignored');
$lead['service']="\xff"; denied_segment(fn()=>$q->enqueue('install_123','invalid_utf8',$phone,$lead,1700000000),'invalid UTF-8 accepted');
// Simulate an encrypted oversized job created by an older release.
$db2=new PDO('sqlite::memory:'); $q2=new IebSmsQueue($db2,$key); $q2->install();
$q2->configureInstallation('install_123',100,true); $q2->consent('install_123',$phone,'admin_1','notice-v1',1700000000);
$q2->enqueue('install_123','legacy',$phone,['name'=>'A','phone'=>'B','service'=>'C'],1700000000);
$nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
$payload=json_encode(['recipient'=>$phone,'lead'=>['name'=>'A','phone'=>'B','service'=>str_repeat('a',130)]],JSON_THROW_ON_ERROR);
$cipher=base64_encode($nonce.sodium_crypto_secretbox($payload,$nonce,$key));
$stmt=$db2->prepare('UPDATE ieb_sms_messages SET ciphertext=?'); $stmt->execute([$cipher]);
$calls=0; $result=$q2->dispatchOne(function() use (&$calls) { ++$calls; return 'accepted'; },1700000000);
check_segment($result==='failed' && $calls===0,'legacy oversized message reached provider');
check_segment($db2->query('SELECT ciphertext FROM ieb_sms_messages')->fetchColumn()==='', 'legacy payload retained');
echo "PASS: single segment GSM, extensions, UCS-2 and malformed UTF-8\n";
