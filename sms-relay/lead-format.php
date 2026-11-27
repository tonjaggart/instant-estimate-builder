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
function ieb_sms_message_text(array $lead): string {
    return 'Taggart lead: '.$lead['name'].' | '.$lead['phone'].' | '.$lead['service'].' | support@taggartmediagroup.com';
}
/** Retain the complete phone; shorten name and service only, on UTF-8 boundaries. */
function ieb_sms_format_lead(array $lead) {
    foreach (['name','phone','service'] as $key) {
        if (!isset($lead[$key]) || !is_string($lead[$key]) || trim($lead[$key])==='' || !preg_match('//u',$lead[$key]) || preg_match('/[\x00-\x1f\x7f]/',$lead[$key])) return false;
    }
    if (strlen($lead['phone'])>160) return false;
    $out=['name'=>'-','phone'=>$lead['phone'],'service'=>'-'];
    if (!ieb_sms_single_segment(ieb_sms_message_text($out))) return false;
    foreach (['name','service'] as $field) {
        $value='';
        // At most 160 characters can fit; bound the temporary split even for huge inputs.
        preg_match('/\A.{0,160}/us',$lead[$field],$prefix);
        foreach (preg_split('//u',$prefix[0],-1,PREG_SPLIT_NO_EMPTY) as $char) {
            $next=$value.$char;
            if (strlen($next)>160) break;
            $candidate=$out; $candidate[$field]=$next;
            if (!ieb_sms_single_segment(ieb_sms_message_text($candidate))) break;
            $value=$next;
        }
        if (trim($value)!=='') $out[$field]=trim($value);
    }
    return $out;
}
