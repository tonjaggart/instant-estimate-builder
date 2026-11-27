<?php
// Synthetic fixtures only; no real provider or network.
require __DIR__.'/../sms-relay/queue.php';
function ck_two($ok,$why) { if (!$ok) throw new RuntimeException($why); }
$lead=['name'=>'李😀','phone'=>'(202) 555-0192','service'=>'Roofing Estimate','low'=>'$14,900','high'=>'$25,800','zip'=>'00005'];
$expected="New Roofing Lead:\n李😀\n\$14,900 - \$25,800\n(202) 555-0192\n00005\nsupport@taggartmediagroup.com";
ck_two(ieb_sms_pilot_six_line($lead)===$expected,'ordinary Unicode must preserve all six lines');
ck_two(function_exists('ieb_sms_within_two_segments'),'two-segment fence missing');
foreach ([[str_repeat('漢',128),true],[str_repeat('漢',129),false],[str_repeat('😀',64),true],[str_repeat('😀',65),false],[str_repeat('A',284),true],[str_repeat('A',285),false],[str_repeat('€',142),true],[str_repeat('€',143),false],[str_repeat('漢',63).'😀'.str_repeat('漢',63),false],["\xff",false]] as [$text,$expectedPass]) ck_two(ieb_sms_within_two_segments($text)===$expectedPass,'two-segment boundary');
$db=new PDO('sqlite::memory:'); $q=new IebSmsQueue($db,random_bytes(32));$q->install();$q->configureInstallation('test',100,true);$phones=['+12025550101','+12025550102'];foreach($phones as $p)$q->consent('test',$p,'admin','synthetic',time());
$sent=[];$fake=function($to,$body)use(&$sent){$sent[]=[$to,$body];return count($sent)%2?'unknown':'accepted';};
for($i=0;$i<101;$i++){$q->enqueue('test','event_'.$i,$phones[$i%2],$lead,time());$out=$q->dispatchOne($fake,time());ck_two($out===($i<100?($i%2?'accepted':'unknown'):'capped'),'monthly attempt cap');}
ck_two(count($sent)===100,'provider called more than monthly limit');foreach($sent as [$to,$body])ck_two($body===$expected,'full six-line message changed');
$oversize=$lead;$oversize['name']=str_repeat('😀',40);try{$q->enqueue('test','too_big',$phones[0],$oversize,time());throw new RuntimeException('oversize accepted');}catch(DomainException $e){}
$db2=new PDO('sqlite::memory:');$q2=new IebSmsQueue($db2,random_bytes(32));$q2->install();$q2->configureInstallation('t',1,true);$q2->consent('t',$phones[0],'admin','synthetic',time());$q2->enqueue('t','one',$phones[0],$lead,time());$calls=0;$provider=function()use(&$calls){$calls++;return 'retryable';};$q2->dispatchOne($provider,time());$q2->enqueue('t','two',$phones[0],$lead,time());ck_two($q2->dispatchOne($provider,time()+61)==='capped' && $calls===1,'retryable attempts must remain charged without retry');
$db3=new PDO('sqlite::memory:');$q3=new IebSmsQueue($db3,random_bytes(32));$q3->install();$q3->configureInstallation('s',10,true);foreach($phones as $p)$q3->consent('s',$p,'admin','synthetic',time());foreach($phones as $i=>$p)$q3->enqueue('s','stop_'.$i,$p,$lead,time());$q3->stop($phones[0],time());$calls=[];$provider=function($to,$body)use(&$calls){$calls[]=[$to,$body];return 'accepted';};ck_two($q3->dispatchOne($provider,time())==='accepted','other recipient dispatch');ck_two($q3->dispatchOne($provider,time())==='empty' && $calls===[[$phones[1],$expected]],'STOP must suppress only stopped recipient');
echo "PASS: Unicode, exact two-recipient alerts, boundaries, attempt cap, retry fence and STOP\n";
