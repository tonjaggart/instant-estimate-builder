<?php
// Production client + signed in-memory relay; fake provider only.
require __DIR__.'/wp-sms-client.php';
function resend_check($ok,$message) { if (!$ok) throw new RuntimeException($message); }
$GLOBALS['options'][1][ieb_sms_state_key()]=['recipients'=>[]];
$GLOBALS['can']=true;
$phone='+12025550181'; $other='+12025550182';
resend_check(ieb_sms_request_enrollment($phone,true),'first request');
resend_check(ieb_sms_request_enrollment($other,true),'other request');
$states=ieb_sms_recipients(); $id=0; $otherState=null;
foreach ($states as $key=>$state) { if ($state['phone']===$phone) $id=(int)$key; else $otherState=$state; }
$old=$states[$id]['request_id'];
$messages=[]; $send=function($to,$text) use (&$messages) { $messages[]=[$to,$text]; return 'accepted'; };
resend_check($verify->dispatch($send,time())==='accepted','first fake send');
preg_match('/\b([0-9]{6})\b/',$messages[0][1],$match); $oldCode=$match[1];
$db->prepare('UPDATE ieb_sms_verifications SET expires_at=? WHERE request_id=?')->execute([time()-1,$old]);
resend_check(!ieb_sms_confirm_enrollment($oldCode,$id),'expired code rejected');
$post=['_wpnonce'=>'valid','operation'=>'resend','recipient'=>(string)$id,'request_id'=>$old];
resend_check(ieb_sms_process_admin($post),'explicit resend accepted');
$rows=ieb_sms_recipients(); $fresh=$rows[$id]['request_id'];
resend_check($fresh!==$old && $rows[$id]['status']==='pending','fresh pending generation');
resend_check(in_array($otherState,$rows,true),'other recipient untouched');
resend_check(ieb_sms_process_admin($post),'duplicate resend is idempotent');
resend_check(ieb_sms_recipients()[$id]['request_id']===$fresh,'duplicate uses same generation');
$count=$db->prepare('SELECT count(*) FROM ieb_sms_verifications WHERE request_id=?'); $count->execute([$fresh]);
resend_check((int)$count->fetchColumn()===1,'one new relay request');
resend_check(!ieb_sms_confirm_enrollment($oldCode,$id),'old code cannot confirm new generation');
while ($verify->dispatch($send,time())==='accepted') {}
$last=end($messages); resend_check($last[0]===$phone,'new code belongs to selected recipient');
preg_match('/\b([0-9]{6})\b/',$last[1],$match); $newCode=$match[1];
resend_check(ieb_sms_confirm_enrollment($newCode,$id),'new code verifies selected recipient');
resend_check(!ieb_sms_process_admin($post),'verified recipient cannot resend');
resend_check(in_array($otherState,ieb_sms_recipients(),true),'other remains pending');
resend_check(strpos(json_encode($GLOBALS['options']),$newCode)===false,'no OTP in state');
ob_start(); ieb_sms_render_settings(); $html=ob_get_clean();
resend_check(str_contains($html,'Resend verification code'),'pending recipient has resend control');
resend_check(str_contains($html,'Remove phone number'),'requested removal label');
$otherId=0; foreach (ieb_sms_recipients() as $key=>$row) if ($row['phone']===$other) $otherId=(int)$key;
$otherOld=ieb_sms_recipients()[$otherId]['request_id'];
$otherPost=['_wpnonce'=>'valid','operation'=>'resend','recipient'=>(string)$otherId,'request_id'=>$otherOld];
$GLOBALS['network_down']=true; resend_check(!ieb_sms_process_admin($otherPost),'revoke outage fails closed'); $GLOBALS['network_down']=false;
resend_check(ieb_sms_recipients()[$otherId]['request_id']===$otherOld,'revoke outage does not rotate');
$GLOBALS['lose_request_response']=true; resend_check(!ieb_sms_process_admin($otherPost),'lost request acknowledgment reported');
$uncertain=ieb_sms_recipients()[$otherId]['request_id'];
ob_start(); ieb_sms_render_settings(); $retryHtml=ob_get_clean();
resend_check(str_contains($retryHtml,'name="request_id" value="'.$otherOld.'"'),'retry form retains original token after lost acknowledgment');
resend_check(ieb_sms_process_admin($otherPost),'lost response can be retried');
resend_check(ieb_sms_recipients()[$otherId]['request_id']===$uncertain,'retry reuses uncertain generation');
$count->execute([$uncertain]); resend_check((int)$count->fetchColumn()===1,'lost response does not generate duplicate challenge');
resend_check(!ieb_sms_process_admin(['_wpnonce'=>'invalid','operation'=>'resend','recipient'=>(string)$otherId,'request_id'=>$uncertain]),'resend requires nonce');
$GLOBALS['can']=false; resend_check(!ieb_sms_resend_enrollment($otherId,$uncertain),'resend requires capability'); $GLOBALS['can']=true;
echo "PASS: expired request resends once and verifies only selected recipient\n";
