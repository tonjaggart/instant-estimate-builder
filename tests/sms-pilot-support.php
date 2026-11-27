<?php
require_once __DIR__.'/../sms-relay/queue.php';
require_once __DIR__.'/../sms-relay/verification.php';
function pilot_assert($condition,$message) { if (!$condition) throw new RuntimeException($message); }
foreach ([['name'=>'Jane Smith','phone'=>'+12025550102','service'=>'Gutter'],['name'=>'😀 José Longsurname','phone'=>'+12025550102','service'=>'Roof']] as $lead) {
 $formatted=ieb_sms_format_lead($lead); pilot_assert(is_array($formatted),'could not format lead');
 $text=ieb_sms_message_text($formatted);pilot_assert(str_contains($text,'support@taggartmediagroup.com'),'lead alert must include support email');pilot_assert(str_contains($text,$lead['phone']),'callback number truncated');pilot_assert(ieb_sms_single_segment($text),'alert exceeds single segment');
}
$db=new PDO('sqlite::memory:');$key=random_bytes(32);$q=new IebSmsQueue($db,$key);$q->install();$q->configureInstallation('test_123',100,true);$v=new IebSmsVerification($db,$key);$v->request('test_123',str_repeat('a',32),'+12025550101','admin_1','admin-sms-v2',true,time());$body='';$result=$v->dispatch(function($phone,$message)use(&$body){$body=$message;return 'accepted';},time());pilot_assert($result==='accepted','fake verification dispatch failed');pilot_assert(str_contains($body,'support@taggartmediagroup.com'),'verification must include support email');pilot_assert(ieb_sms_single_segment($body),'verification exceeds single segment');echo "PASS support contact in single-segment lead and verification alerts\n";
