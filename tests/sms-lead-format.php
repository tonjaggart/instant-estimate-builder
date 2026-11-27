<?php
// Pure formatting tests: no network, WordPress or provider.
require_once __DIR__.'/../sms-relay/queue.php';
function fmt_check($ok,$why) { if (!$ok) throw new RuntimeException($why); }
fmt_check(function_exists('ieb_sms_single_segment'), 'shared GSM/UTF-16 segment counter missing');
fmt_check(ieb_sms_single_segment(str_repeat('é',160)), 'GSM accented character uses one septet');
fmt_check(!ieb_sms_single_segment(str_repeat('é',161)), '161 GSM septets rejected');
fmt_check(ieb_sms_single_segment(str_repeat('€',80)), 'GSM extension 160 septets');
fmt_check(!ieb_sms_single_segment(str_repeat('€',81)), 'GSM extension 162 septets');
fmt_check(ieb_sms_single_segment(str_repeat('漢',70)), '70 UTF-16 units');
fmt_check(!ieb_sms_single_segment(str_repeat('漢',71)), '71 UTF-16 units');
fmt_check(ieb_sms_single_segment(str_repeat('😀',35)), '35 surrogate pairs');
fmt_check(!ieb_sms_single_segment(str_repeat('😀',36)), '36 surrogate pairs');
fmt_check(!ieb_sms_single_segment(str_repeat('`',71)), 'non-GSM ASCII switches encoding');
fmt_check(ieb_sms_single_segment(str_repeat('ñ',160)), 'GSM non-ASCII default alphabet');
fmt_check(!ieb_sms_single_segment(str_repeat('€',79).'^A'), 'mixed extension boundary counts escapes');
fmt_check(!ieb_sms_single_segment("\xff"), 'invalid UTF-8');
$legacy=['name'=>'Jane','phone'=>'+12025550199','service'=>'Gutter'];
fmt_check(ieb_sms_message_text($legacy)==='Taggart lead: Jane | +12025550199 | Gutter | support@taggartmediagroup.com', 'previously queued message text must not change');
foreach ([['José',str_repeat('Long service ',90)],['李😀',str_repeat('屋根😀',100)]] as [$name,$service]) {
    $phone='+1 (202) 555-0199';
    $result=ieb_sms_format_lead(['name'=>$name,'phone'=>$phone,'service'=>$service]);
    fmt_check(is_array($result),'format valid long lead');
    fmt_check($result['phone']===$phone,'complete phone retained');
    fmt_check(str_contains($result['name'], $name), 'name retained where it fits');
    fmt_check($result['service'] !== '-' && str_starts_with($service, $result['service']), 'service retains a real prefix');
    fmt_check(str_contains(ieb_sms_message_text($result), 'support@taggartmediagroup.com'), 'pilot support email retained');
    fmt_check(ieb_sms_single_segment(ieb_sms_message_text($result)),'formatted message is single segment');
}
foreach ([
    ['name'=>str_repeat('長',80), 'phone'=>'+12025550199', 'service'=>str_repeat('屋根',80)],
    ['name'=>'😀😀😀😀😀', 'phone'=>'+12025550199', 'service'=>'Gutter replacement'],
    ['name'=>'Élodie', 'phone'=>'+12025550199', 'service'=>'Gutter cleaning'],
    ['name'=>'李', 'phone'=>'+12025550199', 'service'=>'屋根'],
] as $lead) {
    $result=ieb_sms_format_lead($lead);
    fmt_check(is_array($result), 'valid Unicode lead formats');
    fmt_check($result['phone']===$lead['phone'], 'callback remains complete');
    foreach (['name','service'] as $field) {
        fmt_check($result[$field] !== '-' && str_starts_with($lead[$field],$result[$field]), 'retain actual '.$field.' prefix');
    }
    fmt_check(ieb_sms_single_segment(ieb_sms_message_text($result)), 'Unicode lead stays single segment');
}
fmt_check(ieb_sms_format_lead(['name'=>'A','phone'=>str_repeat('9',200),'service'=>'B'])===false,'unrepresentable phone fails closed');
echo "PASS: segment encoding and safe lead formatting\n";
