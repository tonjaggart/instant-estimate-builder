<?php
// Isolated synthetic relay test; never contacts a provider.
require __DIR__.'/../sms-relay/queue.php';
function ck_exact($x,$message) { if (!$x) throw new RuntimeException($message); }
$db=new PDO('sqlite::memory:'); $q=new IebSmsQueue($db,random_bytes(32)); $q->install(); $q->configureInstallation('test',2,true);
$recipients=['+12025550101','+12025550102'];
$lead=['name'=>'José','phone'=>'(202) 555-0192','service'=>'Roofing Estimate','low'=>'$14,900','high'=>'$25,800','zip'=>'00005'];
foreach ($recipients as $n=>$phone) { $q->consent('test',$phone,'admin','synthetic',time()); ck_exact($q->enqueue('test','event_'.$n,$phone,$lead,time())==='queued','exact lead accepted'); }
$sent=[]; $provider=function($to,$text) use (&$sent) { $sent[]=[$to,$text]; return 'accepted'; };
foreach ($recipients as $n=>$phone) ck_exact($q->dispatchOne($provider,time())==='accepted','dispatch');
$expected="New Roofing Lead:\nJosé\n\$14,900 - \$25,800\n(202) 555-0192\n00005";
ck_exact($sent===[[$recipients[0],$expected],[$recipients[1],$expected]],'exact bytes to both');
$q->enqueue('test','event_cap',$recipients[0],$lead,time()); ck_exact($q->dispatchOne($provider,time())==='capped' && count($sent)===2,'monthly cap prevents provider call');
$oversize=$lead; $oversize['name']=str_repeat('😀',40);
try { $q->enqueue('test','event_oversize',$recipients[0],$oversize,time()); throw new RuntimeException('oversize admitted'); } catch (DomainException $e) {}
$reverse=$lead; $reverse['low']='$25,800'; $reverse['high']='$14,900';
try { $q->enqueue('test','event_reverse',$recipients[0],$reverse,time()); throw new RuntimeException('reversed estimate admitted'); } catch (DomainException $e) {}
$forged=$lead; $forged['zip']="61264\u{2028}forged";
try { $q->enqueue('test','event_forged',$recipients[0],$forged,time()); throw new RuntimeException('unicode line break admitted'); } catch (DomainException $e) {}
$legacy=['name'=>'Jane','phone'=>'2025550192','service'=>'Gutters']; $q->configureInstallation('legacy',2,true); $q->consent('legacy',$recipients[0],'admin','synthetic',time()); $q->enqueue('legacy','old',$recipients[0],$legacy,time()); ck_exact($q->dispatchOne($provider,time())==='accepted','legacy dispatch'); ck_exact(str_contains(end($sent)[1],'support@taggartmediagroup.com'),'legacy preserved');
echo "PASS: exact two-recipient queue, byte-for-byte, oversize fence, legacy preservation\n";
