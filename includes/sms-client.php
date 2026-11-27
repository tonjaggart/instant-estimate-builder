<?php
/** Private, opt-in relay client. No network or sender is enabled by default. */
require_once __DIR__.'/../sms-relay/lead-format.php';
function ieb_sms_configured() {
    if (!defined('IEB_SMS_RELAY_HOST') || !defined('IEB_SMS_INSTALLATION') || !defined('IEB_SMS_SECRET')) return false;
    return is_string(IEB_SMS_RELAY_HOST) && (bool) preg_match('/\A(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}\z/D', IEB_SMS_RELAY_HOST)
        && (IEB_SMS_RELAY_HOST!=='phpstack-1239972-6805622.cloudwaysapps.com' || (defined('IEB_SMS_RELAY_PIN') && IEB_SMS_RELAY_PIN==='159.203.128.214' && function_exists('curl_setopt_array') && class_exists('WpOrg\\Requests\\Transport\\Curl')))
        && is_string(IEB_SMS_INSTALLATION) && (bool) preg_match('/\A[a-zA-Z0-9_-]{8,64}\z/D', IEB_SMS_INSTALLATION)
        && is_string(IEB_SMS_SECRET) && (bool) preg_match('/\A[0-9a-f]{64}\z/D', IEB_SMS_SECRET);
}
// Retain the helper for compatibility, but never resume carrier-email sending.
function ieb_sms_legacy_enabled() {
    return false;
}
function ieb_sms_allowed_user() { return current_user_can(defined('IEB_SENSITIVE_CAPABILITY') ? IEB_SENSITIVE_CAPABILITY : 'manage_options'); }
function ieb_sms_state_key() { return '_ieb_sms_recipient'; }
/** Decode the former single-recipient state without treating legacy preferences as consent. */
function ieb_sms_recipients() {
    $raw=get_option(ieb_sms_state_key(),false);
    if (!is_array($raw)) return [];
    if (isset($raw['status'])) return [1=>$raw];
    return isset($raw['recipients']) && is_array($raw['recipients']) ? $raw['recipients'] : [];
}
function ieb_sms_normalize_phone($phone) {
    if (!is_string($phone) || !preg_match('/\A\+?[0-9 ()\.\-]+\z/D',trim($phone))) return false;
    $value=trim($phone); $plus=substr($value,0,1)==='+'; $digits=preg_replace('/\D/','',$value);
    if (!$plus && strlen($digits)===10) $digits='1'.$digits;
    elseif (!$plus && !(strlen($digits)===11 && $digits[0]==='1')) return false;
    return preg_match('/\A[1-9][0-9]{7,14}\z/D',$digits) ? '+'.$digits : false;
}
/** Serialize all collection updates, re-reading caches after acquiring the DB lock. */
function ieb_sms_mutate_recipients($callback) {
    global $wpdb;
    $lock='ieb_sms_'.substr(hash('sha256',DB_NAME.'|'.$wpdb->options.'|'.ieb_sms_state_key()),0,48);
    if ((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 3)',$lock))!=='1') return false;
    try {
        wp_cache_delete(ieb_sms_state_key(),'options'); wp_cache_delete('notoptions','options'); wp_cache_delete('alloptions','options');
        $raw=get_option(ieb_sms_state_key(),false); $rows=ieb_sms_recipients();
        $extra=is_array($raw) && isset($raw['next_id']) ? ['next_id'=>$raw['next_id']] : [];
        $result=$callback($rows,$extra);
        if ($result===false) return false;
        $new=['recipients'=>$rows]+$extra;
        if ($new!==$raw) {
            $saved=$raw===false ? add_option(ieb_sms_state_key(),$new,'',false) : update_option(ieb_sms_state_key(),$new,false);
            if (!$saved) return false;
        }
        return $result;
    } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock)); }
}
function ieb_sms_change_recipient($id,$old,$new) {
    return ieb_sms_mutate_recipients(function (&$rows) use ($id,$old,$new) {
        if (($rows[$id] ?? null)!==$old) return false;
        $rows[$id]=$new; return true;
    });
}

/** One-hour delivery window matches the relay's experimental queue, not a retention policy. */
function ieb_sms_job_fresh($job) {
    return is_array($job) && isset($job['created_at']) && is_int($job['created_at'])
        && $job['created_at']<=time() && $job['created_at']>time()-3600;
}
/** Cloudways maps this app's own hostname to loopback. Pin only the operator-reviewed origin.
 * The constant is an explicit deployment opt-in, not a general-purpose DNS override. */
function ieb_sms_scoped_post($url,$args) {
    $host='phpstack-1239972-6805622.cloudwaysapps.com';
    if (IEB_SMS_RELAY_HOST!==$host) return wp_safe_remote_post($url,$args);
    if (!in_array($url,array_map(function($path) use ($host) { return 'https://'.$host.$path; }, ['/v1/verification/request','/v1/verification/confirm','/v1/revoke','/v1/events']),true)
        || !defined('IEB_SMS_RELAY_PIN') || IEB_SMS_RELAY_PIN!=='159.203.128.214'
        || !filter_var(IEB_SMS_RELAY_PIN,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4|FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)
        || !function_exists('curl_setopt_array') || !class_exists('WpOrg\\Requests\\Transport\\Curl')) return null;
    $hits=['transport'=>0,'curl'=>0];
    $validate=function($external,$candidate,$requested) use ($host,$url) {
        return $candidate===$host && $requested===$url ? true : $external;
    };
    $transport=function(&$requested,&$headers,&$data,&$type,&$options) use ($url,&$hits) {
        if ($requested!==$url) return;
        if ($type!=='POST' || ($options['verify'] ?? false)===false || ($options['verifyname'] ?? true)!==true || ($options['follow_redirects'] ?? true)!==false) throw new RuntimeException('Unsafe relay transport');
        $options['transport']='WpOrg\\Requests\\Transport\\Curl'; ++$hits['transport'];
    };
    $curl=function($handle,$request,$requested) use ($host,$url,&$hits) {
        if ($requested!==$url) return;
        if (($request['redirection'] ?? null)!==0 || empty($request['sslverify']) || empty($request['reject_unsafe_urls'])) throw new RuntimeException('Unsafe relay request');
        if (!curl_setopt_array($handle,[CURLOPT_RESOLVE=>[$host.':443:'.IEB_SMS_RELAY_PIN],CURLOPT_PROXY=>'',CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_FOLLOWLOCATION=>false])) throw new RuntimeException('Relay pin unavailable');
        ++$hits['curl'];
    };
    add_filter('http_request_host_is_external',$validate,99999,3);
    add_action('requests-requests.before_request',$transport,99999,5);
    add_action('http_api_curl',$curl,99999,3);
    try {
        $response=wp_safe_remote_post($url,$args);
        return $hits===['transport'=>1,'curl'=>1] ? $response : null;
    } finally {
        remove_filter('http_request_host_is_external',$validate,99999);
        remove_action('requests-requests.before_request',$transport,99999);
        remove_action('http_api_curl',$curl,99999);
    }
}
/** Return HTTP status only; never expose or log response bodies or credentials. */
function ieb_sms_post($path, $data) {
    if (!ieb_sms_configured() || !in_array($path, ['/v1/verification/request','/v1/verification/confirm','/v1/revoke','/v1/events'], true)) return 0;
    try {
        $body=wp_json_encode($data); if (!is_string($body)) return 0;
        $timestamp=(string) time(); $nonce=bin2hex(random_bytes(16));
        $message="IEB1\n".IEB_SMS_INSTALLATION."\nPOST\n".$path."\n".$timestamp."\n".$nonce."\n".hash('sha256',$body);
        $signature=hash_hmac('sha256',$message,hex2bin(IEB_SMS_SECRET));
        $url='https://'.IEB_SMS_RELAY_HOST.$path;
        $args=['timeout'=>4,'redirection'=>0,'sslverify'=>true,'reject_unsafe_urls'=>true,'limit_response_size'=>4096,'headers'=>['Content-Type'=>'application/json','X-Installation'=>IEB_SMS_INSTALLATION,'X-Timestamp'=>$timestamp,'X-Nonce'=>$nonce,'X-Signature'=>$signature],'body'=>$body];
        $response=ieb_sms_scoped_post($url,$args);
        if ($response===null || is_wp_error($response)) return 0;
        $status=(int) wp_remote_retrieve_response_code($response);
        if ($status>=200 && $status<300) {
            $expected=['/v1/verification/request'=>['requested'],'/v1/verification/confirm'=>['verified'],'/v1/revoke'=>['revoked'],'/v1/events'=>['queued','duplicate']];
            $decoded=json_decode(wp_remote_retrieve_body($response),true);
            if (!is_array($decoded) || !in_array($decoded['result'] ?? null,$expected[$path],true) || ($path==='/v1/verification/request' ? $status!==202 : $status!==200)) return 0;
            if ($path==='/v1/revoke' && ($decoded['generation_scoped_revoke'] ?? false)!==true) return 0;
        }
        return $status;
    } catch (Throwable $e) { return 0; }
}
// Lifecycle I/O must be serialized: an old revoke must not race a new request.
function ieb_sms_lifecycle($callback) {
    global $wpdb;
    $lock='ieb_life_'.substr(hash('sha256',DB_NAME.'|'.$wpdb->options.'|'.ieb_sms_state_key()),0,48);
    if ((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 3)',$lock))!=='1') return false;
    try {
        wp_cache_delete(ieb_sms_state_key(),'options'); wp_cache_delete('notoptions','options'); wp_cache_delete('alloptions','options');
        return $callback();
    } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock)); }
}
function ieb_sms_request_enrollment($phone,$optin) {
    return ieb_sms_lifecycle(function() use ($phone,$optin) { return ieb_sms_request_enrollment_locked($phone,$optin); });
}
function ieb_sms_request_enrollment_locked($phone, $optin) {
    $phone=ieb_sms_normalize_phone($phone);
    if (!ieb_sms_configured() || !ieb_sms_allowed_user() || $optin!==true || !$phone) return false;
    try {
        $state=ieb_sms_mutate_recipients(function (&$rows,&$extra) use ($phone) {
            foreach ($rows as $id=>$row) {
                if (($row['phone'] ?? '')!==$phone) continue;
                if (($row['status'] ?? '')==='pending') return $row;
                // Revoked rows must be removed before any fresh enrollment, so
                // stale remove submissions can never target a new generation.
                return false;
            }
            if (isset($extra['next_id'])) {
                $id=(int)$extra['next_id'];
                if ($id<1 || $id>9999999999 || isset($rows[$id])) return false;
                $extra['next_id']=$id+1;
            } elseif (!$rows) $id=1;
            else { do { $id=random_int(2,2147483647); } while (isset($rows[$id])); }
            $rows[$id]=['status'=>'pending','phone'=>$phone,'request_id'=>bin2hex(random_bytes(16)),'actor'=>'admin_'.get_current_user_id()];
            return $rows[$id];
        });
        if (!is_array($state)) return false;
        return ieb_sms_post('/v1/verification/request',['request_id'=>$state['request_id'],'phone'=>$state['phone'],'actor'=>$state['actor'],'notice'=>'admin-sms-v2','opt_in'=>true])===202;
    } catch (Throwable $e) { return false; }
}
function ieb_sms_confirm_enrollment($code,$id=1) {
    return ieb_sms_lifecycle(function() use ($code,$id) { return ieb_sms_confirm_enrollment_locked($code,$id); });
}
function ieb_sms_confirm_enrollment_locked($code,$id=1) {
    if (!ieb_sms_configured() || !ieb_sms_allowed_user() || !is_string($code) || !preg_match('/\A[0-9]{6}\z/D',$code)) return false;
    $state=ieb_sms_recipients()[(int)$id] ?? null;
    if (!is_array($state) || ($state['status'] ?? '')!=='pending') return false;
    if (ieb_sms_post('/v1/verification/confirm',['request_id'=>$state['request_id'],'code'=>$code])!==200) return false;
    $new=$state; $new['status']='verified';
    return ieb_sms_change_recipient((int)$id,$state,$new);
}
function ieb_sms_revoke_enrollment($id=1) {
    return ieb_sms_lifecycle(function() use ($id) { return ieb_sms_revoke_enrollment_locked($id); });
}
function ieb_sms_revoke_enrollment_locked($id=1) {
    if (!ieb_sms_allowed_user()) return false;
    $state=ieb_sms_recipients()[(int)$id] ?? null;
    if (!is_array($state) || empty($state['phone'])) return false;
    $new=$state; $new['status']='revoked'; $new['revoke_ack']=false;
    if ($state!==$new && !ieb_sms_change_recipient((int)$id,$state,$new)) return false;
    if (empty($new['request_id']) || ieb_sms_post('/v1/revoke',['phone'=>$new['phone'],'actor'=>$new['actor'],'request_id'=>$new['request_id']])!==200) return false;
    $ack=$new; $ack['revoke_ack']=true;
    return ieb_sms_change_recipient((int)$id,$new,$ack);
}
/** Delete only after a generation-aware relay acknowledges revocation. */
function ieb_sms_remove_recipient($id) {
    if (!ieb_sms_allowed_user()) return false;
    return ieb_sms_lifecycle(function() use ($id) {
        $id=(int)$id;
        $state=ieb_sms_recipients()[$id] ?? null;
        if ($state===null) return true; // Retried submission, never reused ID.
        if (!ieb_sms_revoke_enrollment_locked($id)) return false;
        return ieb_sms_mutate_recipients(function (&$rows,&$extra) use ($id) {
            $state=$rows[$id] ?? null;
            if (!is_array($state) || ($state['status'] ?? '')!=='revoked' || empty($state['revoke_ack'])) return false;
            // Retain only an integer high-water mark, not the deleted phone.
            $max=$id; foreach (array_keys($rows) as $key) $max=max($max,(int)$key);
            $extra['next_id']=max((int)($extra['next_id'] ?? 1),$max+1);
            unset($rows[$id]);
            return true;
        });
    });
}
/** Failure records deliberately contain no lead details or relay response bodies. */
function ieb_sms_record_failure($lead_id, $reason, $status=0) {
    $allowed=['format','relay_rejected','expired','retry_exhausted','schedule_failed','fanout_failed'];
    if (!in_array($reason,$allowed,true)) return;
    $failure=['lead_id'=>(int)$lead_id,'reason'=>$reason,'http_status'=>(int)$status,'time'=>time()];
    update_post_meta((int)$lead_id,'_ieb_sms_failure',$failure);
    update_option('_ieb_sms_last_failure',$failure,false);
}
/** Scope deterministic events to the site's tables, lead and recipient. */
function ieb_sms_event_id($lead_id,$recipient_id) {
    global $wpdb;
    return substr('lead_'.hash_hmac('sha256',$wpdb->postmeta.'|'.$lead_id.'|'.$recipient_id,hex2bin(IEB_SMS_SECRET)),0,64);
}
/** Called after normal email notifications. No relay I/O in the lead request. */
function ieb_sms_queue_lead($lead_id) {
    if (!ieb_sms_configured()) return false;
    global $wpdb;
    $lock='ieb_sms_'.substr(hash('sha256',DB_NAME.'|'.$wpdb->postmeta.'|'.$lead_id),0,48);
    if ((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 3)', $lock)) !== '1') return false;
    try {
        // A previous process may have changed both the outbox and recipient state.
        wp_cache_delete((int)$lead_id,'post_meta');
        wp_cache_delete(ieb_sms_state_key(),'options'); wp_cache_delete('alloptions','options'); wp_cache_delete('notoptions','options');
        $plan=get_post_meta($lead_id,'_ieb_sms_fanout',true);
        if (get_post_meta($lead_id,'_ieb_sms_fanout_done',true)) {
            if ($plan) delete_post_meta($lead_id,'_ieb_sms_fanout');
            return false;
        }
        if (!$plan) {
            $states=ieb_sms_recipients(); $eligible=[];
            foreach ($states as $id=>$state) {
                $id=(int)$id;
                if ($id>0 && is_array($state) && ($state['status'] ?? '')==='verified' && !empty($state['request_id']) && !empty($state['phone'])) $eligible[$id]=$state;
            }
            if (!$eligible) return false;
            $lead=ieb_sms_format_lead(['name'=>(string)get_post_meta($lead_id,'first_name',true),'phone'=>(string)get_post_meta($lead_id,'phone',true),'service'=>(string)get_the_title((int)get_post_meta($lead_id,'_hgm_form_id',true))]);
            if ($lead===false) { ieb_sms_record_failure($lead_id,'format'); return false; }
            $created=time(); $jobs=[];
            foreach ($eligible as $id=>$state) {
                $event=ieb_sms_event_id($lead_id,$id);
                $jobs[]=['uid'=>$id,'event'=>$event,'request_id'=>$state['request_id'],'phone'=>$state['phone'],'lead'=>$lead,'created_at'=>$created];
            }
            // Persist the immutable fanout before creating any per-recipient job.
            // A partial metadata failure is recoverable without losing its snapshot.
            $plan=['created_at'=>$created,'jobs'=>$jobs];
            if (!add_post_meta($lead_id,'_ieb_sms_fanout',wp_slash($plan),true)) {
                ieb_sms_record_failure($lead_id,'fanout_failed'); return false;
            }
        }
        if (!ieb_sms_job_fresh($plan) || !isset($plan['jobs']) || !is_array($plan['jobs'])) {
            ieb_sms_record_failure($lead_id,'expired');
            if (add_post_meta($lead_id,'_ieb_sms_fanout_done','1',true) || get_post_meta($lead_id,'_ieb_sms_fanout_done',true)) delete_post_meta($lead_id,'_ieb_sms_fanout');
            return false;
        }
        $any=false; $incomplete=false;
        foreach ($plan['jobs'] as $job) {
            $id=(int)($job['uid'] ?? 0);
            if ($id<1) { $incomplete=true; continue; }
            $marker=$id===1 ? '_ieb_sms_once' : '_ieb_sms_once_'.$id;
            if (get_post_meta($lead_id,$marker,true)) continue;
            $existing=false;
            foreach (get_post_meta($lead_id,'_ieb_sms_pending',false) as $candidate) {
                if (!is_array($candidate) || (int)($candidate['uid'] ?? 0)!==$id) continue;
                if (ieb_sms_job_fresh($candidate)) { $existing=true; break; }
                ieb_sms_record_failure($lead_id,'expired');
                delete_post_meta($lead_id,'_ieb_sms_pending',wp_slash($candidate));
                if (!add_post_meta($lead_id,$marker,'1',true) && !get_post_meta($lead_id,$marker,true)) $incomplete=true;
                continue 2;
            }
            if (!$existing && !add_post_meta($lead_id,'_ieb_sms_pending',wp_slash($job))) {
                $incomplete=true; ieb_sms_record_failure($lead_id,'fanout_failed'); continue;
            }
            if (!add_post_meta($lead_id,$marker,'1',true) && !get_post_meta($lead_id,$marker,true)) {
                $incomplete=true; ieb_sms_record_failure($lead_id,'fanout_failed'); continue;
            }
            if (!wp_schedule_single_event(time()+1,'ieb_sms_dispatch_lead',[(int)$lead_id,$id,0])) ieb_sms_record_failure($lead_id,'schedule_failed');
            $any=true;
        }
        if (!$incomplete) {
            if (add_post_meta($lead_id,'_ieb_sms_fanout_done','1',true) || get_post_meta($lead_id,'_ieb_sms_fanout_done',true)) delete_post_meta($lead_id,'_ieb_sms_fanout');
            else { $incomplete=true; ieb_sms_record_failure($lead_id,'fanout_failed'); }
        }
        // This separate hook cannot suppress a faster pagination continuation.
        if ($incomplete) wp_schedule_single_event(time()+60,'ieb_sms_recover_failed_fanout');
        return $any;
    } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock)); }
}
function ieb_sms_dispatch_lead($lead_id,$uid,$attempt=0) {
    foreach (get_post_meta($lead_id,'_ieb_sms_pending',false) as $job) {
        if (!is_array($job) || ($job['uid'] ?? 0)!==(int)$uid) continue;
        if (!ieb_sms_job_fresh($job) || get_post_type($lead_id)!=='hgm_lead' || in_array(get_post_status($lead_id),[false,'trash','auto-draft','inherit'],true)) {
            if (!ieb_sms_job_fresh($job) && get_post_type($lead_id)==='hgm_lead') ieb_sms_record_failure($lead_id,'expired');
            delete_post_meta($lead_id,'_ieb_sms_pending',wp_slash($job)); continue;
        }
        if (!ieb_sms_configured()) continue;
        $state=ieb_sms_recipients()[(int)$uid] ?? null;
        if (!is_array($state) || ($state['status'] ?? '')!=='verified' || ($state['request_id'] ?? '')!==($job['request_id'] ?? null) || ($state['phone'] ?? '')!==($job['phone'] ?? null)) {
            delete_post_meta($lead_id,'_ieb_sms_pending',wp_slash($job)); continue;
        }
        $status=ieb_sms_post('/v1/events',['event'=>$job['event'],'phone'=>$job['phone'],'lead'=>$job['lead'],'request_id'=>$job['request_id']]);
        if ($status===0 || $status===429 || $status>=500) {
            if ($attempt<7) {
                if (!wp_schedule_single_event(time()+min(3600,60*(2**$attempt)),'ieb_sms_dispatch_lead',[(int)$lead_id,(int)$uid,(int)$attempt+1])) ieb_sms_record_failure($lead_id,'schedule_failed');
            } else ieb_sms_record_failure($lead_id,'retry_exhausted',$status);
        } else {
            if ($status!==200) ieb_sms_record_failure($lead_id,'relay_rejected',$status);
            // Relay accepted or permanently rejected; repeated event IDs remain idempotent.
            delete_post_meta($lead_id,'_ieb_sms_pending',wp_slash($job));
        }
    }
}
/** Repair lost schedules and restart exhausted transient retries from the durable outbox. */
function ieb_sms_recover_pending() {
    global $wpdb;
    // Fanout intents are independently bounded: an incomplete insert cannot
    // disappear merely because no per-recipient outbox row was created.
    $fanout_cursor=max(0,(int)get_option('_ieb_sms_fanout_cursor',0));
    $intents=$wpdb->get_results($wpdb->prepare(
        "SELECT m.meta_id, m.post_id, m.meta_value, p.post_type, p.post_status FROM {$wpdb->postmeta} m LEFT JOIN {$wpdb->posts} p ON p.ID=m.post_id WHERE m.meta_key=%s AND m.meta_id>%d ORDER BY m.meta_id ASC LIMIT 100",
        '_ieb_sms_fanout',$fanout_cursor
    ));
    if (is_array($intents) && empty($wpdb->last_error)) {
        $retry_intents=false;
        foreach ($intents as $intent) {
            $plan=maybe_unserialize($intent->meta_value);
            if (!ieb_sms_job_fresh($plan) || $intent->post_type!=='hgm_lead' || in_array($intent->post_status,[null,'trash','auto-draft','inherit'],true)) {
                $safe_to_delete=true;
                if ($intent->post_type==='hgm_lead' && !in_array($intent->post_status,[null,'trash','auto-draft','inherit'],true)) {
                    ieb_sms_record_failure((int)$intent->post_id,'expired');
                    $safe_to_delete=add_post_meta((int)$intent->post_id,'_ieb_sms_fanout_done','1',true) || (bool)get_post_meta((int)$intent->post_id,'_ieb_sms_fanout_done',true);
                }
                if ($safe_to_delete) delete_metadata_by_mid('post',(int)$intent->meta_id);
                else $retry_intents=true;
            } elseif (ieb_sms_configured()) {
                ieb_sms_queue_lead((int)$intent->post_id);
                if (metadata_exists('post',(int)$intent->post_id,'_ieb_sms_fanout')) $retry_intents=true;
            }
        }
        // Keep retry state across keyset pages, including an empty final page.
        if ($retry_intents) update_option('_ieb_sms_fanout_retry_needed','1',false);
        update_option('_ieb_sms_fanout_cursor',count($intents)===100 ? (int)end($intents)->meta_id : 0,false);
        if (count($intents)===100) wp_schedule_single_event(time()+5,'ieb_sms_recover_pending_batch');
        elseif (get_option('_ieb_sms_fanout_retry_needed',false)) {
            if (wp_schedule_single_event(time()+60,'ieb_sms_recover_pending_batch') || wp_next_scheduled('ieb_sms_recover_pending_batch')) delete_option('_ieb_sms_fanout_retry_needed');
        }
    }
    $cursor = max(0, (int) get_option('_ieb_sms_recovery_cursor', 0));
    // Keyset pagination bounds each pass even when one lead has many recipients.
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT m.meta_id, m.post_id, m.meta_value, p.post_type, p.post_status FROM {$wpdb->postmeta} m LEFT JOIN {$wpdb->posts} p ON p.ID=m.post_id WHERE m.meta_key=%s AND m.meta_id>%d ORDER BY m.meta_id ASC LIMIT 100",
        '_ieb_sms_pending', $cursor
    ));
    if (!is_array($rows) || !empty($wpdb->last_error)) return; // Preserve cursor on database failure.
    foreach ($rows as $row) {
        $job = maybe_unserialize($row->meta_value);
        if (!ieb_sms_job_fresh($job) || $row->post_type!=='hgm_lead' || in_array($row->post_status,[null,'trash','auto-draft','inherit'],true) || empty($job['uid'])) {
            if (!ieb_sms_job_fresh($job) && $row->post_type==='hgm_lead' && !in_array($row->post_status,[null,'trash','auto-draft','inherit'],true)) ieb_sms_record_failure((int)$row->post_id,'expired');
            delete_metadata_by_mid('post',(int)$row->meta_id);
        } elseif (ieb_sms_configured()) wp_schedule_single_event(time()+1,'ieb_sms_dispatch_lead',[(int)$row->post_id,(int)$job['uid'],0]);
    }
    update_option('_ieb_sms_recovery_cursor', count($rows) === 100 ? (int) end($rows)->meta_id : 0, false);
    if (count($rows)===100) wp_schedule_single_event(time()+5,'ieb_sms_recover_pending_batch');
}
function ieb_sms_schedule_recovery() {
    if (ieb_sms_configured() && !wp_next_scheduled('ieb_sms_recover_pending')) wp_schedule_event(time()+300,'hourly','ieb_sms_recover_pending');
}
add_action('init','ieb_sms_schedule_recovery');
add_action('ieb_sms_recover_pending','ieb_sms_recover_pending');
add_action('ieb_sms_recover_pending_batch','ieb_sms_recover_pending');
add_action('ieb_sms_recover_failed_fanout','ieb_sms_recover_pending');
function ieb_sms_process_admin($post) {
    if (!ieb_sms_allowed_user() || !is_array($post) || !isset($post['_wpnonce']) || !is_string($post['_wpnonce']) || !wp_verify_nonce(wp_unslash($post['_wpnonce']),'ieb_sms_enrollment')) return false;
    $operation=$post['operation'] ?? '';
    if ($operation==='request') {
        $phone=$post['phone'] ?? ''; if (!is_string($phone)) return false;
        return ieb_sms_request_enrollment(trim(wp_unslash($phone)),($post['opt_in'] ?? null)==='1');
    }
    $recipient=$post['recipient'] ?? '';
    if (!is_string($recipient) || !preg_match('/\A[1-9][0-9]{0,9}\z/D',$recipient)) return false;
    if ($operation==='confirm') {
        $code=$post['code'] ?? ''; return is_string($code) && ieb_sms_confirm_enrollment(trim(wp_unslash($code)),(int)$recipient);
    }
    if ($operation==='revoke') return ieb_sms_revoke_enrollment((int)$recipient);
    if ($operation==='remove') return ieb_sms_remove_recipient((int)$recipient);
    return false;
}
function ieb_sms_render_settings() {
    if (!ieb_sms_allowed_user()) return;
    echo '<div class="hgm-dashboard-card ieb-notifications-card"><h2>Instant Estimate Builder SMS Alerts</h2>';
    echo '<p>Receive a text alert when a new estimate request is submitted. Alerts may include the prospective customer’s name, phone number, and requested service.</p>';
    $failure=get_option('_ieb_sms_last_failure',false);
    if (is_array($failure) && !empty($failure['lead_id'])) {
        $failureLead=(int)$failure['lead_id']; $reason=(string)($failure['reason'] ?? '');
        $descriptions=['format'=>'could not format the lead','relay_rejected'=>'relay rejected the alert','expired'=>'alert expired before relay acceptance','retry_exhausted'=>'relay retries were exhausted','schedule_failed'=>'dispatch could not be scheduled','fanout_failed'=>'one or more recipient alerts could not be queued'];
        $description=$descriptions[$reason] ?? 'alert encountered a problem';
        echo '<p><strong>Most recent SMS alert issue:</strong> Lead #'.$failureLead.' — '.esc_html($description).' ('.esc_html(gmdate('Y-m-d H:i', (int)($failure['time'] ?? 0))).' UTC). The issue may have since recovered. <a href="'.esc_url(admin_url('admin.php?page=instant-estimate-lead&id='.$failureLead)).'">Review the lead</a> and contact support@taggartmediagroup.com if needed. Relay acceptance does not confirm delivery.</p>';
    }
    if (!ieb_sms_configured()) echo '<div class="notice notice-warning inline"><p><strong>The SMS relay is not configured.</strong> Verification requests and new alerts cannot be sent until it is configured; numbers entered here will not be saved. Previously queued relay messages may still be processed. Reply STOP or contact support@taggartmediagroup.com for help.</p></div>';
    $states=ieb_sms_recipients();
    if (!$states) echo '<p>No numbers enrolled. Each recipient must agree and verify their own phone.</p>';
    if (isset($_GET['sms_result']) && $_GET['sms_result']==='error') echo '<div class="notice notice-error inline" role="alert"><p>Action could not be confirmed. Check the number’s status, retry, or contact support@taggartmediagroup.com. A removal that could not reach the relay may not cancel messages already queued there.</p></div>';
    elseif (isset($_GET['sms_result']) && $_GET['sms_result']==='removed') echo '<p>Number is no longer listed. A message already being sent cannot be recalled.</p>';
    elseif (isset($_GET['sms_result']) && $_GET['sms_result']==='ok') echo '<p>Action processed. Requesting a code does not confirm delivery or enrollment.</p>';
    foreach ($states as $id=>$state) {
        $status=$state['status'] ?? ''; $phone=$state['phone'] ?? '';
        echo '<section><h3>'.esc_html($phone).'</h3>';
        if ($status==='verified') echo '<p>Verified. Delivery is not guaranteed; STOP or relay restrictions may prevent messages. Reply STOP to opt out or HELP for help.</p>';
        elseif ($status==='pending') echo '<p>Verification is pending. If you received a code, enter it below. If the request failed or the code expired, remove the number and enroll again.</p>';
        elseif ($status==='revoked') echo '<p>Removal is not complete. Alerts are disabled locally, but messages already queued at the relay may still be sent. Retry removal, reply STOP, or contact support@taggartmediagroup.com.</p>';
        if ($status==='pending') {
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'; wp_nonce_field('ieb_sms_enrollment');
            echo '<input type="hidden" name="action" value="ieb_sms_enrollment"><input type="hidden" name="operation" value="confirm"><input type="hidden" name="recipient" value="'.esc_attr((string)$id).'"><p><label>Verification code <input name="code" inputmode="numeric" pattern="[0-9]{6}" autocomplete="one-time-code" required></label></p><button class="button" type="submit">Confirm code</button></form>';
        }
        if (in_array($status,['verified','pending','revoked'],true)) {
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'; wp_nonce_field('ieb_sms_enrollment');
            echo '<input type="hidden" name="action" value="ieb_sms_enrollment"><input type="hidden" name="operation" value="remove"><input type="hidden" name="recipient" value="'.esc_attr((string)$id).'"><p><button class="button" type="submit">'.($status==='revoked'?'Retry removal':'Remove number').'</button></p></form>';
        }
        echo '</section>';
    }
    echo '<h3>Add a number</h3><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'; wp_nonce_field('ieb_sms_enrollment');
    echo '<input type="hidden" name="action" value="ieb_sms_enrollment"><input type="hidden" name="operation" value="request">';
    echo '<p><label for="ieb-sms-phone">Your mobile number</label><br><input id="ieb-sms-phone" name="phone" type="tel" autocomplete="tel" placeholder="10-digit mobile number" class="regular-text" required></p>';
    echo '<p><label><input type="checkbox" name="opt_in" value="1" required> I am the user of this number. I agree to receive recurring Instant Estimate Builder estimate-request text alerts from Taggart Media Group LLC at the mobile number I provide. Alerts may include lead contact details. Message frequency varies. Message and data rates may apply. Reply HELP for help or STOP to unsubscribe.</label></p>';
    echo '<p>For this pilot, replying HELP receives a generic carrier/provider response; for program support email support@taggartmediagroup.com. Reply STOP to unsubscribe.</p>';
    echo '<p><a href="https://taggartmediagroup.com/instant-estimate-builder-sms-alert-terms/">SMS Alert Terms</a> | <a href="https://taggartmediagroup.com/privacy-policy/">Privacy Policy</a>. Help: support@taggartmediagroup.com.</p>';
    echo '<button type="submit" class="button button-primary">Request verification code</button></form>';
    echo '</div>';
}
function ieb_sms_admin_post() {
    $result=ieb_sms_process_admin($_POST);
    $notice=$result ? (($_POST['operation'] ?? '')==='remove' ? 'removed' : 'ok') : 'error';
    wp_safe_redirect(admin_url('admin.php?page=instant-estimate-notifications&tab=text&sms_result='.$notice));
    exit;
}
add_action('admin_post_ieb_sms_enrollment','ieb_sms_admin_post');
add_action('ieb_sms_dispatch_lead','ieb_sms_dispatch_lead',10,3);
