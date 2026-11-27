<?php
// Offline synthetic regression: no provider calls, no WordPress, no personal data.
require __DIR__.'/../sms-relay/queue.php';
function ck_unicode($ok,$message) { if (!$ok) throw new RuntimeException($message); }
$lead=['name'=>'雪','phone'=>'(202) 555-0192','service'=>'Gutter Replacement','low'=>'$960','high'=>'$2,400','zip'=>'61264'];
$expected="New Gutter Replacement Lead:\n雪\n\$960 - \$2,400\n(202) 555-0192\n61264\nsupport@taggartmediagroup.com";
ck_unicode(ieb_sms_pilot_six_line($lead)===$expected,'ordinary Unicode lead must retain every field and support footer');
ck_unicode(ieb_sms_tollfree_segments($expected)===2,'ordinary Unicode lead costs two segments');
ck_unicode(ieb_sms_tollfree_segments(str_repeat('a',160))===1,'160 GSM septets');
ck_unicode(ieb_sms_tollfree_segments(str_repeat('a',161))===2,'161 GSM septets');
ck_unicode(ieb_sms_tollfree_segments(str_repeat('a',304))===2,'304 GSM septets');
ck_unicode(ieb_sms_tollfree_segments(str_repeat('a',305))===3,'305 GSM septets');
ck_unicode(ieb_sms_tollfree_segments(str_repeat('^',152))===2,'GSM extension counts two');
ck_unicode(ieb_sms_tollfree_segments(str_repeat('^',153))===3,'GSM extension boundary');
ck_unicode(ieb_sms_tollfree_segments(str_repeat('雪',70))===1,'70 UTF-16 units');
ck_unicode(ieb_sms_tollfree_segments(str_repeat('雪',71))===2,'71 UTF-16 units');
ck_unicode(ieb_sms_tollfree_segments(str_repeat('雪',132))===2,'132 UTF-16 units');
ck_unicode(ieb_sms_tollfree_segments(str_repeat('雪',133))===3,'133 UTF-16 units');
ck_unicode(ieb_sms_tollfree_segments(str_repeat('雪',65).'😀'.str_repeat('雪',65))===3,'surrogate pair cannot split between segments');
ck_unicode(ieb_sms_tollfree_segments("\xff")===false,'invalid UTF-8 rejected');
$db=new PDO('sqlite::memory:'); $q=new IebSmsQueue($db,random_bytes(32)); $q->install(); $q->configureInstallation('synthetic',2,true); $q->configureSegmentCap('synthetic',4); $now=time();
$recipients=['+12025550101','+12025550102'];
foreach($recipients as $i=>$phone) { $q->consent('synthetic',$phone,'admin','test',$now); ck_unicode($q->enqueue('synthetic','event_'.$i,$phone,$lead,$now)==='queued','enqueue recipient'); }
$sent=[]; $provider=function($phone,$text)use(&$sent){$sent[]=[$phone,$text];return 'accepted';};
ck_unicode($q->dispatchOne($provider,$now)==='accepted','first dispatch');
ck_unicode($q->dispatchOne($provider,$now)==='accepted','second dispatch');
ck_unicode($sent===[[$recipients[0],$expected],[$recipients[1],$expected]],'each recipient receives exact six lines');
ck_unicode((int)$db->query('SELECT SUM(segments) FROM ieb_sms_message_segments')->fetchColumn()===4,'four segment reservations recorded');
$q->enqueue('synthetic','third',$recipients[0],$lead,$now); ck_unicode($q->dispatchOne($provider,$now)==='capped' && count($sent)===2,'two-alert cap remains two alerts');
$q->configureInstallation('segments',10,true); $q->configureSegmentCap('segments',3); $q->consent('segments',$recipients[0],'admin','test',$now);
$q->enqueue('segments','s1',$recipients[0],$lead,$now); $q->enqueue('segments','s2',$recipients[0],$lead,$now);
ck_unicode($q->dispatchOne($provider,$now)==='accepted','first segment-budget job');
ck_unicode($q->dispatchOne($provider,$now)==='capped','separate segment hard cap prevents second 2-segment call');
$q->configureInstallation('stop',10,true); $q->consent('stop',$recipients[0],'admin','test',$now);
$q->enqueue('stop','stop_event',$recipients[0],$lead,$now); $q->stop($recipients[0],$now);
$prior=count($sent); ck_unicode($q->dispatchOne($provider,$now)==='empty' && count($sent)===$prior,'STOP suppresses queued message');
echo "PASS: six exact Unicode lines, toll-free encoding boundaries, two recipients, alert and segment accounting\n";
