<?php
require_once __DIR__.'/../sms-relay/lead-format.php';
function check_six($ok,$why) { if (!$ok) throw new RuntimeException($why); }
$base=['name'=>'J','phone'=>'1','service'=>'G','low'=>'$1','high'=>'$2','zip'=>'1'];
$expected="New G Lead:\nJ\n\$1 - \$2\n1\n1\nsupport@taggartmediagroup.com";
check_six(ieb_sms_pilot_six_line($base)===$expected,'six lines, exact bytes and support');
// Fill the available GSM budget, including extension-table characters (two septets).
$empty=ieb_sms_pilot_six_line($base); $used=strlen($empty);
$fit=$base; $fit['name']='J'.str_repeat('A',160-$used);
check_six(ieb_sms_pilot_six_line($fit)!==false,'160 GSM septets allowed');
$fit['name'].='A'; check_six(ieb_sms_pilot_six_line($fit)===false,'161 GSM septets rejected');
$fit=$base; $fit['name']='J'.str_repeat('^',intdiv(160-$used,2));
check_six(ieb_sms_pilot_six_line($fit)!==false,'GSM extension budget accepted');
$fit['name'].='^'; check_six(ieb_sms_pilot_six_line($fit)===false,'GSM extension escape counted');
// A non-GSM scalar switches the whole message to UTF-16; surrogate pairs cost two.
$fit=$base; $fit['name']='雪'.str_repeat('A',70-$used);
check_six(ieb_sms_pilot_six_line($fit)!==false,'70 UTF-16 units allowed');
$fit['name'].='A'; check_six(ieb_sms_pilot_six_line($fit)===false,'71 UTF-16 units rejected');
$fit=$base; $fit['name']='😀'.str_repeat('A',69-$used);
check_six(ieb_sms_pilot_six_line($fit)!==false,'emoji counts as two UTF-16 units');
$fit['name'].='A'; check_six(ieb_sms_pilot_six_line($fit)===false,'emoji boundary rejected');
$real=['name'=>'雪','phone'=>'(202) 555-0192','service'=>'Gutter Replacement','low'=>'$960','high'=>'$2,400','zip'=>'61264'];
check_six(ieb_sms_pilot_six_line($real)===false,'actual-style non-GSM lead fails rather than truncates');
echo "PASS: six-line support, GSM/extensions and UTF-16/emoji boundaries; no truncation\n";
