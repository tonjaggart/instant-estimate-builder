<?php
/** Private, opt-in relay client. No network or sender is enabled by default. */
function ieb_sms_configured() {
    if (!defined('IEB_SMS_RELAY_HOST') || !defined('IEB_SMS_INSTALLATION') || !defined('IEB_SMS_SECRET')) return false;
    return is_string(IEB_SMS_RELAY_HOST) && (bool) preg_match('/\A(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}\z/D', IEB_SMS_RELAY_HOST)
        && is_string(IEB_SMS_INSTALLATION) && (bool) preg_match('/\A[a-zA-Z0-9_-]{8,64}\z/D', IEB_SMS_INSTALLATION)
        && is_string(IEB_SMS_SECRET) && (bool) preg_match('/\A[0-9a-f]{64}\z/D', IEB_SMS_SECRET);
}
// Once selected, do not silently revive the old email-to-carrier route if the
// relay configuration is removed. Existing sites remain unchanged until selected.
function ieb_sms_legacy_enabled() {
    if (ieb_sms_configured()) {
        if (!get_option('_ieb_sms_relay_selected',false)) update_option('_ieb_sms_relay_selected','1',false);
        return false;
    }
    return !get_option('_ieb_sms_relay_selected',false);
}
add_action('init','ieb_sms_legacy_enabled');
function ieb_sms_allowed_user() { return current_user_can(defined('IEB_SENSITIVE_CAPABILITY') ? IEB_SENSITIVE_CAPABILITY : 'manage_options'); }
function ieb_sms_state_key() { return '_ieb_sms_recipient'; }
/** Compare-and-set the one installation-wide recipient under a MySQL advisory lock. */
function ieb_sms_change_state($old, $new) {
    global $wpdb;
    $lock='ieb_sms_'.substr(hash('sha256', DB_NAME.'|'.$wpdb->options.'|'.ieb_sms_state_key()),0,48);
    if ((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 3)', $lock)) !== '1') return false;
    try {
        // Another PHP process may have changed the row while this process waited.
        // Clear all option cache paths before comparing under the database lock.
        wp_cache_delete(ieb_sms_state_key(),'options');
        wp_cache_delete('notoptions','options');
        wp_cache_delete('alloptions','options');
        if (get_option(ieb_sms_state_key(), false) !== $old) return false;
        if ($old === false) $saved=add_option(ieb_sms_state_key(), $new, '', false);
        else $saved=update_option(ieb_sms_state_key(), $new, false);
        return $saved && get_option(ieb_sms_state_key(), false) === $new;
    } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock)); }
}

/** One-hour delivery window matches the relay's experimental queue, not a retention policy. */
function ieb_sms_job_fresh($job) {
    return is_array($job) && isset($job['created_at']) && is_int($job['created_at'])
        && $job['created_at']<=time() && $job['created_at']>time()-3600;
}
/** Return HTTP status only; never expose or log response bodies or credentials. */
function ieb_sms_post($path, $data) {
    if (!ieb_sms_configured() || !in_array($path, ['/v1/verification/request','/v1/verification/confirm','/v1/revoke','/v1/events'], true)) return 0;
    try {
        $body=wp_json_encode($data); if (!is_string($body)) return 0;
        $timestamp=(string) time(); $nonce=bin2hex(random_bytes(16));
        $message="IEB1\n".IEB_SMS_INSTALLATION."\nPOST\n".$path."\n".$timestamp."\n".$nonce."\n".hash('sha256',$body);
        $signature=hash_hmac('sha256',$message,hex2bin(IEB_SMS_SECRET));
        $response=wp_safe_remote_post('https://'.IEB_SMS_RELAY_HOST.$path, ['timeout'=>4,'redirection'=>0,'sslverify'=>true,'reject_unsafe_urls'=>true,'limit_response_size'=>4096,'headers'=>['Content-Type'=>'application/json','X-Installation'=>IEB_SMS_INSTALLATION,'X-Timestamp'=>$timestamp,'X-Nonce'=>$nonce,'X-Signature'=>$signature],'body'=>$body]);
        if (is_wp_error($response)) return 0;
        $status=(int) wp_remote_retrieve_response_code($response);
        if ($status>=200 && $status<300) {
            $expected=['/v1/verification/request'=>['requested'],'/v1/verification/confirm'=>['verified'],'/v1/revoke'=>['revoked'],'/v1/events'=>['queued','duplicate']];
            $decoded=json_decode(wp_remote_retrieve_body($response),true);
            if (!is_array($decoded) || !in_array($decoded['result'] ?? null,$expected[$path],true) || ($path==='/v1/verification/request' ? $status!==202 : $status!==200)) return 0;
        }
        return $status;
    } catch (Throwable $e) { return 0; }
}
function ieb_sms_request_enrollment($phone, $optin) {
    if (!ieb_sms_configured() || !ieb_sms_allowed_user() || $optin !== true || !is_string($phone) || !preg_match('/\A\+[1-9][0-9]{7,14}\z/D',$phone)) return false;
    $uid=get_current_user_id(); $state=get_option(ieb_sms_state_key(),false);
    if (is_array($state) && (($state['status'] ?? '') === 'verified' || (($state['status'] ?? '') === 'pending' && ($state['phone'] ?? '') !== $phone) || (($state['status'] ?? '') === 'revoked' && empty($state['revoke_ack'])))) return false;
    try {
        if (!is_array($state) || ($state['status'] ?? '') !== 'pending' || ($state['phone'] ?? '') !== $phone) {
            $old=$state; $state=['status'=>'pending','phone'=>$phone,'request_id'=>bin2hex(random_bytes(16)),'actor'=>'admin_'.$uid];
            if (!ieb_sms_change_state($old,$state)) return false;
        }
        return ieb_sms_post('/v1/verification/request',['request_id'=>$state['request_id'],'phone'=>$phone,'actor'=>$state['actor'],'notice'=>'admin-sms-v2','opt_in'=>true])===202;
    } catch (Throwable $e) { return false; }
}
function ieb_sms_confirm_enrollment($code) {
    if (!ieb_sms_configured() || !ieb_sms_allowed_user() || !is_string($code) || !preg_match('/\A[0-9]{6}\z/D',$code)) return false;
    $uid=get_current_user_id(); $state=get_option(ieb_sms_state_key(),false);
    if (!is_array($state) || ($state['status'] ?? '') !== 'pending') return false;
    if (ieb_sms_post('/v1/verification/confirm',['request_id'=>$state['request_id'],'code'=>$code])!==200) return false;
    $old=$state; $state['status']='verified';
    if (!ieb_sms_change_state($old,$state)) return false;
    return true;
}
function ieb_sms_revoke_enrollment() {
    if (!ieb_sms_allowed_user()) return false;
    $uid=get_current_user_id(); $state=get_option(ieb_sms_state_key(),false);
    if (!is_array($state) || empty($state['phone'])) return false;
    $old=$state; $state['status']='revoked'; $state['revoke_ack']=false;
    if ($old!==$state && !ieb_sms_change_state($old,$state)) return false;
    if (empty($state['request_id']) || ieb_sms_post('/v1/revoke',['phone'=>$state['phone'],'actor'=>$state['actor'],'request_id'=>$state['request_id']])!==200) return false;
    $old=$state; $state['revoke_ack']=true;
    if (!ieb_sms_change_state($old,$state)) return false;
    return true;
}
/** Called after normal email notifications. No relay I/O in the lead request. */
function ieb_sms_queue_lead($lead_id) {
    if (!ieb_sms_configured()) return false;
    $lead=['name'=>(string)get_post_meta($lead_id,'first_name',true),'phone'=>(string)get_post_meta($lead_id,'phone',true),'service'=>(string)get_the_title((int)get_post_meta($lead_id,'_hgm_form_id',true))];
    foreach ($lead as $value) if (trim($value)==='' || strlen($value)>160 || preg_match('/[\x00-\x1f\x7f]/',$value)) return false;
    $state=get_option(ieb_sms_state_key(),false);
    if (!is_array($state) || ($state['status'] ?? '')!=='verified' || empty($state['request_id']) || empty($state['phone'])) return false;
    global $wpdb;
    $lock='ieb_sms_'.substr(hash('sha256',DB_NAME.'|'.$wpdb->postmeta.'|'.$lead_id),0,48);
    if ((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 3)', $lock)) !== '1') return false;
    try {
        // The lead fields above may have primed this process's stale meta cache.
        wp_cache_delete((int)$lead_id,'post_meta');
        if (get_post_meta($lead_id,'_ieb_sms_once',true)) return false;
        $job=null;
        foreach (get_post_meta($lead_id,'_ieb_sms_pending',false) as $candidate) {
            if (ieb_sms_job_fresh($candidate)) { $job=$candidate; break; }
            delete_post_meta($lead_id,'_ieb_sms_pending',wp_slash($candidate));
            return false;
        }
        if (!$job) {
            $job=['uid'=>1,'event'=>substr('lead_'.hash_hmac('sha256',(string)$lead_id,hex2bin(IEB_SMS_SECRET)),0,64),'request_id'=>$state['request_id'],'phone'=>$state['phone'],'lead'=>$lead,'created_at'=>time()];
            if (!add_post_meta($lead_id,'_ieb_sms_pending',wp_slash($job))) return false;
        }
        // Marker contains no lead details and prevents re-enqueue after dispatch.
        if (!add_post_meta($lead_id,'_ieb_sms_once','1',true)) return false;
        return (bool)wp_schedule_single_event(time()+1,'ieb_sms_dispatch_lead',[(int)$lead_id,1,0]);
    } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock)); }
}
function ieb_sms_dispatch_lead($lead_id,$uid,$attempt=0) {
    foreach (get_post_meta($lead_id,'_ieb_sms_pending',false) as $job) {
        if (!is_array($job) || ($job['uid'] ?? 0)!==(int)$uid) continue;
        if (!ieb_sms_job_fresh($job) || get_post_type($lead_id)!=='hgm_lead' || in_array(get_post_status($lead_id),[false,'trash','auto-draft','inherit'],true)) {
            delete_post_meta($lead_id,'_ieb_sms_pending',wp_slash($job)); continue;
        }
        if (!ieb_sms_configured()) continue;
        $state=get_option(ieb_sms_state_key(),false);
        if (!is_array($state) || ($state['status'] ?? '')!=='verified' || ($state['request_id'] ?? '')!==($job['request_id'] ?? null) || ($state['phone'] ?? '')!==($job['phone'] ?? null)) {
            delete_post_meta($lead_id,'_ieb_sms_pending',wp_slash($job)); continue;
        }
        $status=ieb_sms_post('/v1/events',['event'=>$job['event'],'phone'=>$job['phone'],'lead'=>$job['lead'],'request_id'=>$job['request_id']]);
        if ($status===0 || $status===429 || $status>=500) {
            if ($attempt<7) wp_schedule_single_event(time()+min(3600,60*(2**$attempt)),'ieb_sms_dispatch_lead',[(int)$lead_id,(int)$uid,(int)$attempt+1]);
        } else {
            // Relay accepted or permanently rejected; repeated event IDs remain idempotent.
            delete_post_meta($lead_id,'_ieb_sms_pending',wp_slash($job));
        }
    }
}
/** Repair lost schedules and restart exhausted transient retries from the durable outbox. */
function ieb_sms_recover_pending() {
    global $wpdb;
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
            delete_metadata_by_mid('post',(int)$row->meta_id);
        } elseif (ieb_sms_configured()) wp_schedule_single_event(time()+1,'ieb_sms_dispatch_lead',[(int)$row->post_id,(int)$job['uid'],0]);
    }
    update_option('_ieb_sms_recovery_cursor', count($rows) === 100 ? (int) end($rows)->meta_id : 0, false);
}
function ieb_sms_schedule_recovery() {
    if (ieb_sms_configured() && !wp_next_scheduled('ieb_sms_recover_pending')) wp_schedule_event(time()+300,'hourly','ieb_sms_recover_pending');
}
add_action('init','ieb_sms_schedule_recovery');
add_action('ieb_sms_recover_pending','ieb_sms_recover_pending');
function ieb_sms_process_admin($post) {
    if (!ieb_sms_allowed_user() || !is_array($post) || !isset($post['_wpnonce']) || !is_string($post['_wpnonce']) || !wp_verify_nonce(wp_unslash($post['_wpnonce']),'ieb_sms_enrollment')) return false;
    $operation=$post['operation'] ?? '';
    if ($operation==='request') {
        $phone=$post['phone'] ?? ''; if (!is_string($phone)) return false;
        return ieb_sms_request_enrollment(trim(wp_unslash($phone)),($post['opt_in'] ?? null)==='1');
    }
    if ($operation==='confirm') {
        $code=$post['code'] ?? ''; return is_string($code) && ieb_sms_confirm_enrollment(trim(wp_unslash($code)));
    }
    if ($operation==='revoke') return ieb_sms_revoke_enrollment();
    return false;
}
function ieb_sms_render_settings() {
    if (!ieb_sms_allowed_user()) return;
    echo '<div class="hgm-dashboard-card ieb-notifications-card"><h2>Administrator SMS alerts</h2>';
    if (!ieb_sms_configured()) { echo '<p>The SMS relay is not configured. This site cannot initiate or manage alerts; previously queued relay messages may still be processed. Reply STOP or contact support@taggartmediagroup.com for help.</p></div>'; return; }
    $state=get_option(ieb_sms_state_key(),false); $status=is_array($state)?($state['status'] ?? ''):'';
    if ($status==='verified') echo '<p>Number '.esc_html($state['phone'] ?? '').' was verified. Delivery is not guaranteed; STOP or relay restrictions may prevent messages. Reply STOP to opt out or HELP for help.</p>';
    elseif ($status==='pending') echo '<p>Verification requested for '.esc_html($state['phone'] ?? '').'. This does not confirm delivery or enrollment. Enter the code sent to your own number. Opt out first to change numbers or request a new code after expiry.</p>';
    elseif ($status==='revoked') echo '<p>Alerts are disabled locally. If relay revocation failed, contact support@taggartmediagroup.com or reply STOP.</p>';
    else echo '<p>Not enrolled. A verification text may be sent to the number you provide.</p>';
    if (isset($_GET['sms_result']) && $_GET['sms_result']==='error') echo '<p>Action could not be confirmed. Check your status, retry, or contact support@taggartmediagroup.com. An opt-out that could not reach the relay may not cancel messages already queued there.</p>';
    elseif (isset($_GET['sms_result']) && $_GET['sms_result']==='ok') echo '<p>Action processed. See your status below; requesting a code does not confirm delivery.</p>';
    if ($status!=='verified') {
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'; wp_nonce_field('ieb_sms_enrollment');
        echo '<input type="hidden" name="action" value="ieb_sms_enrollment"><input type="hidden" name="operation" value="request">';
        echo '<p><label for="ieb-sms-phone">Your mobile number in international format</label><br><input id="ieb-sms-phone" name="phone" type="tel" placeholder="+15551234567" required></p>';
        echo '<p><label><input type="checkbox" name="opt_in" value="1" required> I am the user of this number. I agree to receive recurring Instant Estimate Builder estimate-request text alerts from Taggart Media Group LLC at the mobile number I provide. Alerts may include lead contact details. Message frequency varies. Message and data rates may apply. Reply HELP for help or STOP to unsubscribe.</label></p>';
        echo '<p><a href="https://taggartmediagroup.com/instant-estimate-builder-sms-alert-terms/">SMS Alert Terms</a> | <a href="https://taggartmediagroup.com/privacy-policy/">Privacy Policy</a>. Help: support@taggartmediagroup.com.</p>';
        echo '<button type="submit" class="button button-primary">Request verification code</button></form>';
    }
    if ($status==='pending') {
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'; wp_nonce_field('ieb_sms_enrollment');
        echo '<input type="hidden" name="action" value="ieb_sms_enrollment"><input type="hidden" name="operation" value="confirm"><p><label>Verification code <input name="code" inputmode="numeric" pattern="[0-9]{6}" autocomplete="one-time-code" required></label></p><button class="button" type="submit">Confirm code</button></form>';
    }
    if (in_array($status,['verified','pending','revoked'],true)) {
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'; wp_nonce_field('ieb_sms_enrollment');
        echo '<input type="hidden" name="action" value="ieb_sms_enrollment"><input type="hidden" name="operation" value="revoke"><p><button class="button" type="submit">Opt out</button></p></form>';
    }
    echo '</div>';
}
function ieb_sms_admin_post() {
    $result=ieb_sms_process_admin($_POST);
    wp_safe_redirect(admin_url('admin.php?page=instant-estimate-notifications&tab=text&sms_result='.($result?'ok':'error')));
    exit;
}
add_action('admin_post_ieb_sms_enrollment','ieb_sms_admin_post');
add_action('ieb_sms_dispatch_lead','ieb_sms_dispatch_lead',10,3);
