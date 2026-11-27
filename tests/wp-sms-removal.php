<?php
// Runs production client and authenticated in-memory relay through WP stubs, no network.
require __DIR__.'/wp-sms-client.php';
function removal_check($ok,$message) { if (!$ok) throw new RuntimeException($message); }
$GLOBALS['options'][1][ieb_sms_state_key()]=['recipients'=>[]];
$GLOBALS['can']=true; $GLOBALS['write_race']=false; $GLOBALS['confirm_race']=false;
$phoneA='+12025550171'; $phoneB='+12025550172';
$find=function($phone) { foreach (ieb_sms_recipients() as $id=>$row) if ($row['phone']===$phone) return (int)$id; return 0; };
foreach ([$phoneA,$phoneB] as $phone) {
    removal_check(ieb_sms_request_enrollment($phone,true),'request');
    removal_check($verify->dispatch($send,time())==='accepted','synthetic verification');
    preg_match('/\b([0-9]{6})\b/',end($messages)[1],$code);
    removal_check(ieb_sms_confirm_enrollment($code[1],$find($phone)),'confirm');
}
$a=$find($phoneA); $b=$find($phoneB); $originalB=ieb_sms_recipients()[$b];
$GLOBALS['options'][1][ieb_sms_state_key()]['recipients'][99]=['phone'=>'+120****0199','status'=>'pending'];
ob_start(); ieb_sms_render_settings(); $html=ob_get_clean();
removal_check(str_contains($html,'class="button button-primary hgm-button-primary" type="submit">Confirm code</button>'),'confirm code uses plugin primary button');
removal_check(str_contains($html,'class="button-link ieb-sms-remove"'),'remove rendered as text link');
removal_check(str_contains($html,'value="remove_prepare"'),'no-JS removal begins with confirmation');
removal_check(str_contains($html,'window.confirm('),'removal asks for confirmation');
removal_check(!ieb_sms_process_admin(['_wpnonce'=>'valid','operation'=>'remove_prepare','recipient'=>(string)$a]),'preparing removal cannot remove a number');
removal_check(ieb_sms_recipients()[$a]['status']==='verified','preparing removal preserves state');
$_GET=['sms_confirm'=>(string)$a,'sms_confirm_nonce'=>'valid'];
ob_start(); ieb_sms_render_settings(); $confirmHtml=ob_get_clean(); $_GET=[];
removal_check(str_contains($confirmHtml,'Are you sure you want to remove'),'no-JS confirmation is displayed');
removal_check(str_contains($confirmHtml,'value="remove"'),'no-JS confirmation explicitly submits removal');
removal_check(str_contains($confirmHtml,'>Cancel</a>'),'no-JS confirmation offers cancellation');
$_GET=['sms_confirm'=>(string)$a,'sms_confirm_nonce'=>'invalid'];
ob_start(); ieb_sms_render_settings(); $invalidHtml=ob_get_clean(); $_GET=[];
removal_check(!str_contains($invalidHtml,'Are you sure you want to remove'),'invalid confirmation nonce is ignored');
removal_check(ieb_sms_recipients()[$b]===$originalB,'confirmation leaves other recipient untouched');
removal_check(!ieb_sms_process_admin(['_wpnonce'=>'invalid','operation'=>'remove','recipient'=>(string)$a]),'nonce rejected');
$GLOBALS['can']=false; removal_check(!ieb_sms_process_admin(['_wpnonce'=>'valid','operation'=>'remove','recipient'=>(string)$a]),'capability rejected'); $GLOBALS['can']=true;
$GLOBALS['network_down']=true;
removal_check(!ieb_sms_process_admin(['_wpnonce'=>'valid','operation'=>'remove','recipient'=>(string)$a]),'outage reported');
removal_check(isset(ieb_sms_recipients()[$a]) && ieb_sms_recipients()[$a]['status']==='revoked','failed removal leaves local tombstone');
removal_check(!ieb_sms_request_enrollment($phoneA,true),'reenrollment blocked until relay ack');
$GLOBALS['network_down']=false;
$GLOBALS['lose_response']=true;
removal_check(!ieb_sms_process_admin(['_wpnonce'=>'valid','operation'=>'remove','recipient'=>(string)$a]),'lost relay acknowledgment does not falsely remove row');
removal_check(isset(ieb_sms_recipients()[$a]) && !ieb_sms_request_enrollment($phoneA,true),'lost acknowledgment retains tombstone');
$GLOBALS['legacy_relay']=true;
removal_check(!ieb_sms_process_admin(['_wpnonce'=>'valid','operation'=>'remove','recipient'=>(string)$a]),'older relay cannot acknowledge generation-safe removal');
removal_check(isset(ieb_sms_recipients()[$a]) && !ieb_sms_request_enrollment($phoneA,true),'older relay leaves tombstone and blocks reenrollment');
$GLOBALS['legacy_relay']=false;
removal_check(ieb_sms_process_admin(['_wpnonce'=>'valid','operation'=>'remove','recipient'=>(string)$a]),'retry removal');
removal_check(!$find($phoneA),'recipient permanently removed');
removal_check(ieb_sms_recipients()[$b]===$originalB,'other recipient unchanged');
removal_check(!ieb_sms_request_enrollment($phoneA,false),'fresh consent required');
removal_check(ieb_sms_request_enrollment($phoneA,true),'new enrollment');
$new=$find($phoneA); removal_check(ieb_sms_recipients()[$new]['status']==='pending','fresh verification required');
echo "PASS: secure removal, outage retry, isolation and fresh consent\n";
