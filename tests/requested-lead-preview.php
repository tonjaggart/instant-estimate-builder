<?php
// Offline-only specification; this preview is NOT wired to the relay.
require_once __DIR__.'/../sms-relay/lead-format.php';
function requested_check($value,$message) { if (!$value) throw new RuntimeException($message); }
requested_check(function_exists('ieb_sms_requested_preview'),'requested preview not implemented');
$leads=[
 [['name'=>'Jane','phone'=>'(202) 555-0192','service'=>'Gutter Replacement','low'=>'$1,970','high'=>'$3,830','zip'=>'61264'], "New Gutter Replacement Lead:\nJane\n\$1,970 - \$3,830\n(202) 555-0192\n61264"],
 [['name'=>'José','phone'=>'202-555-0193','service'=>'Roofing Estimate','low'=>'$14,900','high'=>'$25,800','zip'=>'00005'], "New Roofing Lead:\nJosé\n\$14,900 - \$25,800\n202-555-0193\n00005"],
];
foreach ($leads as [$lead,$expected]) {
 $actual=ieb_sms_requested_preview($lead);
 requested_check($actual===$expected,'exact five-line format and data mapping');
 requested_check(ieb_sms_single_segment($actual),'representable example must fit');
 $sent=[]; $fakeProvider=function($phone,$body) use (&$sent) { $sent[]=[$phone,$body]; }; $fakeProvider('+12025550101',$actual);
 requested_check($sent[0][1]===$expected,'fake provider receives exact bytes');
}
$lead=$leads[0][0];
foreach (['name','phone','service','low','high','zip'] as $field) { $bad=$lead; $bad[$field]=''; requested_check(ieb_sms_requested_preview($bad)===false,'missing '.$field.' must fail closed'); }
$bad=$lead; $bad['low']='1970'; requested_check(ieb_sms_requested_preview($bad)===false,'unformatted estimate must fail closed');
$bad=$lead; $bad['zip']="61264\nforged"; requested_check(ieb_sms_requested_preview($bad)===false,'multiline field must fail closed');
$bad=$lead; $bad['name']=str_repeat('😀',40); requested_check(ieb_sms_requested_preview($bad)===false,'oversized Unicode must fail, not truncate fields');
requested_check(str_contains(ieb_sms_message_text(['name'=>'Jane','phone'=>'2025550192','service'=>'Gutter']), 'support@taggartmediagroup.com'),'current pilot remains unchanged');
echo "PASS: exact offline preview, saved data examples, fake provider, Unicode fence and legacy preservation\n";
