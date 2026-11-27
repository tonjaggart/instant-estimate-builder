<?php
require_once __DIR__.'/../sms-relay/lead-format.php';
function check_six($ok,$why) { if (!$ok) throw new RuntimeException($why); }
$base=['name'=>'J','phone'=>'1','service'=>'G','low'=>'$1','high'=>'$2','zip'=>'1'];
$expected="New G Lead:\nJ\n\$1 - \$2\n1\n1\nsupport@taggartmediagroup.com";
check_six(ieb_sms_pilot_six_line($base)===$expected,'six lines, exact bytes and support');
$used=strlen($expected);
$fit=$base; $fit['name']='J'.str_repeat('A',160-$used);
check_six(ieb_sms_pilot_six_line($fit)!==false,'160 GSM septets allowed');
$fit['name'].='A'; check_six(ieb_sms_pilot_six_line($fit)!==false,'161 GSM septets allowed in two segments');
$fit=$base; $fit['name']='雪'.str_repeat('A',128-$used);
check_six(ieb_sms_pilot_six_line($fit)!==false,'128 UTF-16 units allowed');
$fit['name'].='A'; check_six(ieb_sms_pilot_six_line($fit)===false,'129 UTF-16 units rejected on carrier-safe bound');
$fit=$base; $fit['name']='😀'.str_repeat('A',127-$used);
check_six(ieb_sms_pilot_six_line($fit)!==false,'emoji counts as two UTF-16 units');
$fit['name'].='A'; check_six(ieb_sms_pilot_six_line($fit)===false,'emoji boundary rejected');
$real=['name'=>'雪','phone'=>'(202) 555-0192','service'=>'Gutter Replacement','low'=>'$960','high'=>'$2,400','zip'=>'61264'];
check_six(ieb_sms_pilot_six_line($real)!==false,'ordinary non-GSM lead fits without truncation');
echo "PASS: six lines, support, toll-free GSM and UTF-16/emoji boundaries\n";
