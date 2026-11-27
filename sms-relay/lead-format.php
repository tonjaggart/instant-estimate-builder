<?php
/** Shared cost fence and deterministic lead formatting; no external dependencies. */
function ieb_sms_single_segment(string $message): bool {
    if (!preg_match('//u', $message)) return false;
    $basic="@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";
    $extended="\f^{}\\[~]|€";
    $gsm=true; $septets=0; $units=0;
    foreach (preg_split('//u',$message,-1,PREG_SPLIT_NO_EMPTY) as $char) {
        $units+=strlen($char)===4 ? 2 : 1;
        if (strpos($basic,$char)!==false) ++$septets;
        elseif (strpos($extended,$char)!==false) $septets+=2;
        else $gsm=false;
    }
    return $gsm ? $septets<=160 : $units<=70;
}
function ieb_sms_legacy_message_text(array $lead): string {
    return 'Taggart lead: '.$lead['name'].' | '.$lead['phone'].' | '.$lead['service'].' | support@taggartmediagroup.com';
}
function ieb_sms_message_text(array $lead): string {
    $legacy=ieb_sms_legacy_message_text($lead);
    if (ieb_sms_single_segment($legacy)) return $legacy;
    return 'Taggart: '.$lead['name'].' | '.$lead['phone'].' | '.$lead['service'].' support@taggartmediagroup.com';
}
/** Original five-line preview; never send this preview without the pilot footer. */
function ieb_sms_requested_preview(array $lead) {
    foreach (['name','phone','service','low','high','zip'] as $field) {
        if (!isset($lead[$field]) || !is_string($lead[$field]) || trim($lead[$field])==='' || strlen($lead[$field])>160 || !preg_match('//u',$lead[$field]) || preg_match('/[\x{0000}-\x{001f}\x{007f}-\x{009f}\x{2028}\x{2029}]/u',$lead[$field])) return false;
    }
    foreach (['low','high'] as $field) {
        if (!preg_match('/\A\$(?:0|[1-9][0-9]{0,2}(?:,[0-9]{3})*)\z/D',$lead[$field])) return false;
    }
    $low=(float)str_replace(['$',','],'',$lead['low']); $high=(float)str_replace(['$',','],'',$lead['high']);
    if ($high<=0 || $low>$high) return false;
    $service=trim(preg_replace('/\s+Estimate\s*$/iu','',$lead['service']));
    if ($service==='') return false;
    $text='New '.$service." Lead:\n".trim($lead['name'])."\n".$lead['low'].' - '.$lead['high']."\n".trim($lead['phone'])."\n".trim($lead['zip']);
    return ieb_sms_single_segment($text) ? $text : false;
}
/** Heritage pilot: preserve all fields and include the required support contact. */
function ieb_sms_pilot_six_line(array $lead) {
    $text=ieb_sms_requested_preview($lead);
    if ($text===false) return false;
    $text.="\nsupport@taggartmediagroup.com";
    return ieb_sms_single_segment($text) ? $text : false;
}
/** Retain the complete phone; shorten name and service only, on UTF-8 boundaries. */
function ieb_sms_format_lead(array $lead) {
    foreach (['name','phone','service'] as $key) {
        if (!isset($lead[$key]) || !is_string($lead[$key]) || trim($lead[$key])==='' || !preg_match('//u',$lead[$key]) || preg_match('/[\x00-\x1f\x7f]/',$lead[$key])) return false;
    }
    if (strlen($lead['phone'])>160) return false;
    $out=['name'=>'','phone'=>$lead['phone'],'service'=>''];
    if (!ieb_sms_single_segment(ieb_sms_message_text($out))) return false;
    $chars=[]; $indices=['name'=>0,'service'=>0];
    foreach (['name','service'] as $field) {
        // At most 160 characters can fit; bound the temporary split even for huge inputs.
        preg_match('/\A.{0,160}/us',trim($lead[$field]),$prefix);
        $chars[$field]=preg_split('//u',$prefix[0],-1,PREG_SPLIT_NO_EMPTY);
    }
    // Share scarce Unicode space rather than allowing a long name to crowd out service.
    do {
        $progress=false;
        foreach (['name','service'] as $field) {
            $i=$indices[$field];
            if (!isset($chars[$field][$i])) continue;
            $next=$out[$field].$chars[$field][$i];
            if (strlen($next)>160) { $indices[$field]=count($chars[$field]); continue; }
            $candidate=$out; $candidate[$field]=$next;
            if (!ieb_sms_single_segment(ieb_sms_message_text($candidate))) { $indices[$field]=count($chars[$field]); continue; }
            $out[$field]=$next; ++$indices[$field]; $progress=true;
        }
    } while ($progress);
    foreach (['name','service'] as $field) {
        $out[$field]=trim($out[$field]);
        if ($out[$field]==='') return false;
    }
    return $out;
}
