<?php
// Isolated WP function stubs; the actual signed relay API and encrypted queue run in memory.
error_reporting(E_ALL);
function add_action(...$args) {}
function wp_cache_delete(...$args) { return true; }
define('DB_NAME','stub');
class SmsStubDb {
    public $usermeta='usermeta', $options='options', $postmeta='postmeta', $posts='posts';
    function prepare($query,...$args) { return [$query,$args]; }
    function get_var($query) { if (!empty($GLOBALS['write_race']) && str_contains($query[0],'GET_LOCK')) { $GLOBALS['write_race']=false; $GLOBALS['options'][1][ieb_sms_state_key()]['recipients'][1]['revoke_ack']=false; } return '1'; }
    function get_results($query) {
        if ($query[1][0]==='_ieb_sms_fanout') return []; // No partial inserts in this stub suite.
        $cursor=$query[1][1]; $rows=[]; $id=0;
        foreach ($GLOBALS['meta'] ?? [] as $lead=>$values) foreach ($values['_ieb_sms_pending'] ?? [] as $job) {
            ++$id; if ($id>$cursor) $rows[]=(object)['meta_id'=>$id,'post_id'=>$lead,'meta_value'=>serialize($job),'post_type'=>'hgm_lead','post_status'=>'publish'];
        }
        return array_slice($rows,0,100);
    }
}
$GLOBALS['wpdb']=new SmsStubDb();
function get_option($k,$default=false) { return $GLOBALS['options'][get_current_blog_id()][$k] ?? $default; }
function add_option($k,$v,$deprecated='', $autoload=null) { if (isset($GLOBALS['options'][get_current_blog_id()][$k])) return false; $GLOBALS['options'][get_current_blog_id()][$k]=$v; return true; }
function update_option($k,$v,$autoload=null) { $GLOBALS['options'][get_current_blog_id()][$k]=$v; return true; }
function maybe_unserialize($v) { return unserialize($v); }
function get_current_user_id() { return $GLOBALS['current_uid'] ?? 7; }
function get_current_blog_id() { return $GLOBALS['blog_id'] ?? 1; }
function current_user_can($cap) { return !empty($GLOBALS['can']); }
function user_can($uid,$cap) { return !empty($GLOBALS['can']); }
function get_user_meta($id,$key,$single=true) { return $GLOBALS['users'][$id][$key] ?? ''; }
function update_user_meta($id,$key,$value,$previous=null) { if (!empty($GLOBALS['write_race'])) { $GLOBALS['write_race']=false; $GLOBALS['users'][$id][$key]['revoke_ack']=false; } if ($previous!==null && ($GLOBALS['users'][$id][$key] ?? '')!==$previous) return false; $GLOBALS['users'][$id][$key]=$value; return true; }
function add_user_meta($id,$key,$value,$unique=false) { if ($unique && isset($GLOBALS['users'][$id][$key])) return false; $GLOBALS['users'][$id][$key]=$value; return true; }
function get_users($args) { return array_keys($GLOBALS['users'] ?? []); }
function wp_json_encode($v) { return json_encode($v,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); }
function wp_verify_nonce($value,$action) { return $value==='valid' && $action==='ieb_sms_enrollment'; }
function wp_unslash($v) { return is_array($v) ? array_map('wp_unslash',$v) : (is_string($v) ? stripslashes($v) : $v); }
function wp_slash($v) { return is_array($v) ? array_map('wp_slash',$v) : (is_string($v) ? addslashes($v) : $v); }
function admin_url($path) { return 'https://wp.example.test/wp-admin/'.$path; }
function esc_url($s) { return htmlspecialchars($s, ENT_QUOTES); }
function esc_attr($s) { return htmlspecialchars($s, ENT_QUOTES); }
function esc_html($s) { return htmlspecialchars($s, ENT_QUOTES); }
function wp_nonce_field($action) { echo '<input name="_wpnonce" value="valid">'; }
function wp_safe_remote_post($url,$args) {
    $GLOBALS['http'][]=[$url,$args];
    if (!empty($GLOBALS['forced_status'])) return ['response'=>['code'=>$GLOBALS['forced_status']],'body'=>'{}'];
    if (!empty($GLOBALS['network_down'])) return ['response'=>['code'=>503],'body'=>'{}'];
    if (!empty($GLOBALS['invalid_success'])) return ['response'=>['code'=>200],'body'=>'{}'];
    if ($port=getenv('IEB_TEST_LOOPBACK_PORT')) {
        if (!preg_match('~\Ahttps://relay\.example\.test(/v1/[a-z/_-]+)\z~D',$url,$match) || $args['sslverify']!==true || $args['redirection']!==0 || $args['reject_unsafe_urls']!==true) throw new RuntimeException('Unsafe test URL');
        $headers=[]; foreach ($args['headers'] as $k=>$v) $headers[]=$k.': '.$v;
        $ch=curl_init('http://127.0.0.1:'.(int)$port.$match[1]);
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$args['body'],CURLOPT_HTTPHEADER=>$headers,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>5]);
        $body=curl_exec($ch); $status=curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
        return ['response'=>['code'=>$status],'body'=>$body];
    }
    $h=$args['headers']; [$status,$body]=$GLOBALS['api']->handle('POST',parse_url($url,PHP_URL_PATH),['installation'=>$h['X-Installation'],'timestamp'=>$h['X-Timestamp'],'nonce'=>$h['X-Nonce'],'signature'=>$h['X-Signature']],$args['body'],time());
    if (!empty($GLOBALS['lose_request_response']) && str_ends_with($url,'/v1/verification/request')) { $GLOBALS['lose_request_response']=false; return ['response'=>['code'=>503],'body'=>'{}']; }
    if (!empty($GLOBALS['legacy_relay']) && str_ends_with($url,'/v1/revoke')) unset($body['generation_scoped_revoke']);
    return ['response'=>['code'=>$status],'body'=>json_encode($body)];
}
function is_wp_error($v) { return false; }
function wp_remote_retrieve_response_code($v) {
    if (!empty($GLOBALS['lose_response'])) { $GLOBALS['lose_response']=false; return 503; }
    if (!empty($GLOBALS['confirm_race'])) {
        $GLOBALS['confirm_race']=false; $key=ieb_sms_state_key(); $id=$GLOBALS['confirm_race_id'] ?? 1; $GLOBALS['options'][1][$key]['recipients'][$id]['status']='revoked'; $GLOBALS['options'][1][$key]['recipients'][$id]['revoke_ack']=false;
    }
    return $v['response']['code'];
}
function wp_remote_retrieve_body($v) { return $v['body']; }
function wp_schedule_single_event(...$args) { if (!empty($GLOBALS['schedule_failure'])) return false; $GLOBALS['scheduled'][]=$args; return true; }
function get_posts($args) { $ids=[]; foreach ($GLOBALS['meta'] ?? [] as $id=>$meta) if (!empty($meta['_ieb_sms_pending'])) $ids[]=$id; return $ids; }
function get_post_meta($id,$key,$single=true) { $v=$GLOBALS['meta'][$id][$key] ?? ''; return $single ? (in_array($key,['_ieb_sms_once','_ieb_sms_fanout','_ieb_sms_fanout_done'],true) || strpos($key,'_ieb_sms_once_')===0 ? ($v[0] ?? '') : $v) : (is_array($v) ? $v : []); }
function update_post_meta($id,$key,$value) { $GLOBALS['meta'][$id][$key]=wp_unslash($value); return true; }
function add_post_meta($id,$key,$value,$unique=false) { if ($unique && !empty($GLOBALS['meta'][$id][$key])) return false; $GLOBALS['meta'][$id][$key][]=wp_unslash($value); return true; }
function delete_post_meta($id,$key,$value=null) { if ($value===null) { unset($GLOBALS['meta'][$id][$key]); return true; } $value=wp_unslash($value); $GLOBALS['meta'][$id][$key]=array_values(array_filter($GLOBALS['meta'][$id][$key] ?? [],function($v) use ($value) { return $v!==$value; })); return true; }
function get_the_title($id) { return $GLOBALS['test_title'] ?? 'Gutter Replacement'; }
function get_post_type($id) { return 'hgm_lead'; }
function get_post_status($id) { return 'publish'; }
function expect($value,$message) { if (!$value) { fwrite(STDERR,"FAIL: $message\n"); exit(1); } }
$client=__DIR__.'/../includes/sms-client.php'; if (is_file($client)) require $client;
expect(function_exists('ieb_sms_event_id'), 'event ID helper exists');
expect(function_exists('ieb_sms_request_enrollment'), 'enrollment function is implemented');
expect(ieb_sms_request_enrollment('+15551230101',true) === false, 'unconfigured enrollment fails closed');
expect(empty($GLOBALS['http']), 'unconfigured enrollment never calls HTTP');
require __DIR__.'/../sms-relay/auth.php'; require __DIR__.'/../sms-relay/queue.php'; require __DIR__.'/../sms-relay/verification.php'; require __DIR__.'/../sms-relay/api.php';
define('IEB_SMS_RELAY_HOST','relay.example.test'); define('IEB_SMS_INSTALLATION','test_install'); define('IEB_SMS_SECRET',getenv('IEB_TEST_KEY') ?: bin2hex(random_bytes(32)));
$original_table=$GLOBALS['wpdb']->postmeta;
$GLOBALS['wpdb']->postmeta='wp_1_postmeta'; $siteOne=ieb_sms_event_id(42,1);
$GLOBALS['wpdb']->postmeta='wp_2_postmeta'; $siteTwo=ieb_sms_event_id(42,1);
$GLOBALS['wpdb']->postmeta=$original_table;
expect($siteOne!==$siteTwo, 'same lead and recipient ID in different site tables must not collide');
$db=new PDO(getenv('IEB_TEST_DB') ? 'sqlite:'.getenv('IEB_TEST_DB') : 'sqlite::memory:'); $key=getenv('IEB_TEST_CRYPT') ? hex2bin(getenv('IEB_TEST_CRYPT')) : random_bytes(32); $queue=new IebSmsQueue($db,$key); $verify=new IebSmsVerification($db,$key); $queue->install(); ieb_auth_install_schema($db); $queue->configureInstallation(IEB_SMS_INSTALLATION,10,true); $GLOBALS['api']=new IebRelayApi($db,$queue,[IEB_SMS_INSTALLATION=>IEB_SMS_SECRET],$verify);
$GLOBALS['can']=false; expect(!ieb_sms_request_enrollment('+15551230101',true), 'capability enforced');
$GLOBALS['invalid_success']=true; expect(ieb_sms_post('/v1/events',[])===0, 'malformed success does not acknowledge delivery'); $GLOBALS['invalid_success']=false;
$GLOBALS['can']=true; expect(!ieb_sms_request_enrollment('+15551230101',false), 'unchecked consent rejected');
expect(ieb_sms_process_admin(['_wpnonce'=>'valid','operation'=>'request','phone'=>'+15551230101','opt_in'=>'1']), 'request accepted through admin handler');
$last=end($GLOBALS['http']); $request_body=json_decode($last[1]['body'],true); expect(($request_body['notice'] ?? '')==='admin-sms-v2', 'consent version matches preview-based notice');
expect(!ieb_sms_request_enrollment('+12025550103',false), 'second number also requires its own consent');
$GLOBALS['meta'][42]=['first_name'=>'Synthetic \\ Lead','phone'=>'+15551230102','_hgm_form_id'=>8];
expect(ieb_sms_queue_lead(42)===false, 'pending consent cannot queue');
$messages=[]; $send=function($to,$body) use (&$messages) { $messages[]=[$to,$body]; return 'accepted'; };
expect($verify->dispatch($send,time())==='accepted', 'verification dispatch to fake provider');
expect($messages[0][0]==='+15551230101', 'verification only sent to opted-in admin');
preg_match('/\b([0-9]{6})\b/',$messages[0][1],$m); $code=$m[1];
expect(!ieb_sms_confirm_enrollment('abcdef'), 'bad code rejected');
$GLOBALS['lose_response']=true;
expect(!ieb_sms_confirm_enrollment($code), 'lost confirmation response remains pending locally');
expect(($GLOBALS['options'][1][ieb_sms_state_key()]['recipients'][1]['status'] ?? '')==='pending', 'lost confirmation does not falsely update local state');
expect(ieb_sms_process_admin(['_wpnonce'=>'valid','operation'=>'confirm','recipient'=>'1','code'=>$code]), 'correct code enables enrollment through admin handler');
$GLOBALS['blog_id']=2; expect(!ieb_sms_queue_lead(42), 'multisite enrollment state isolated by site'); $GLOBALS['blog_id']=1;
expect(strpos(json_encode($GLOBALS['options']),$code)===false, 'OTP not stored');
$GLOBALS['can']=false;
expect(ieb_sms_queue_lead(42), 'recipient consent does not depend on the WordPress user role');
$GLOBALS['can']=true;
// Another administrator manages the same single installation-wide enrollment.
$GLOBALS['current_uid']=8;
expect(!ieb_sms_request_enrollment('+15551230101',true), 'a second admin cannot duplicate a verified recipient');
$GLOBALS['current_uid']=7;
expect(!ieb_sms_queue_lead(42), 'the same lead cannot be queued twice');
expect(count($GLOBALS['scheduled'])===1, 'lead queued without HTTP');
$before=count($GLOBALS['http']); ieb_sms_dispatch_lead(...$GLOBALS['scheduled'][0][2]);
expect(count($GLOBALS['http'])===$before+1, 'worker sent signed request');
expect($queue->dispatchOne($send,time())==='accepted', 'fake provider got lead alert');
expect(count($messages)===2 && $messages[1][0]==='+15551230101' && strpos($messages[1][1],'Synthetic \\ Lead')!==false && strpos($messages[1][1],'+15551230102')!==false && strpos($messages[1][1],'Gutter Replacement')!==false, 'only administrator receives requested lead fields');
expect(!ieb_sms_queue_lead(42), 'a previously handled lead cannot create another SMS');
// Retries reuse the event and payload, but use fresh authentication nonces.
$GLOBALS['meta'][43]=['first_name'=>'Retry','phone'=>'+15551230104','_hgm_form_id'=>8];
expect(ieb_sms_queue_lead(43), 'second lead scheduled');
$GLOBALS['network_down']=true; ieb_sms_dispatch_lead(...$GLOBALS['scheduled'][1][2]);
expect(count($GLOBALS['scheduled'])===3 && $GLOBALS['scheduled'][2][2][2]===1, 'temporary failure schedules retry');
$GLOBALS['network_down']=false; ieb_sms_dispatch_lead(...$GLOBALS['scheduled'][2][2]);
$retry=$GLOBALS['http'][count($GLOBALS['http'])-1]; $first=$GLOBALS['http'][count($GLOBALS['http'])-2];
expect($first[1]['body']===$retry[1]['body'] && $first[1]['headers']['X-Nonce']!==$retry[1]['headers']['X-Nonce'], 'retry stable payload and fresh nonce');
ieb_sms_dispatch_lead(...$GLOBALS['scheduled'][2][2]);
expect($queue->dispatchOne($send,time())==='accepted' && $queue->dispatchOne($send,time())==='empty', 'duplicate retry cannot cause duplicate provider send');
$GLOBALS['meta'][44]=['first_name'=>'Recovery','phone'=>'+15551230105','_hgm_form_id'=>8];
$GLOBALS['schedule_failure']=true; ieb_sms_queue_lead(44); $GLOBALS['schedule_failure']=false;
expect(function_exists('ieb_sms_recover_pending'), 'recovery sweep exists');
ieb_sms_recover_pending();
$recovered=end($GLOBALS['scheduled']); expect($recovered[2][0]===44, 'sweep recovers failed initial schedule');
ieb_sms_dispatch_lead(...$recovered[2]); expect($queue->dispatchOne($send,time())==='accepted', 'recovered event delivers to fake provider');
$GLOBALS['test_title']=str_repeat('Very long synthetic service ',30);
$GLOBALS['meta'][45]=['first_name'=>'José','phone'=>'+15555550109','_hgm_form_id'=>8];
expect(ieb_sms_queue_lead(45), 'accent and long title queued');
ieb_sms_dispatch_lead(45,1,0);
expect($queue->dispatchOne($send,time())==='accepted', 'accent and long title reach fake provider');
$body=end($messages)[1]; expect(str_contains($body,'José') && str_contains($body,'+15555550109'), 'name and full phone preserved');
$GLOBALS['test_title']=str_repeat('屋根😀',40);
$GLOBALS['meta'][48]=['first_name'=>'李😀','phone'=>'+15555550111','_hgm_form_id'=>8];
expect(ieb_sms_queue_lead(48),'Unicode lead queued');
ieb_sms_dispatch_lead(48,1,0);
expect($queue->dispatchOne($send,time())==='accepted','Unicode lead reaches fake provider');
$body=end($messages)[1]; expect(str_contains($body,'李😀') && str_contains($body,'+15555550111') && ieb_sms_single_segment($body),'Unicode and full phone fit one segment');
$GLOBALS['meta'][46]=['first_name'=>'Invalid','phone'=>str_repeat('9',200),'_hgm_form_id'=>8];
expect(!ieb_sms_queue_lead(46), 'unrepresentable phone rejected');
expect(!empty($GLOBALS['meta'][46]['_ieb_sms_failure']), 'invalid format leaves safe durable failure');
$GLOBALS['test_title']='Gutter Replacement';
$GLOBALS['meta'][47]=['first_name'=>'Failure','phone'=>'+15555550110','_hgm_form_id'=>8];
expect(ieb_sms_queue_lead(47), 'permanent failure fixture queued');
$GLOBALS['forced_status']=409; ieb_sms_dispatch_lead(47,1,0); unset($GLOBALS['forced_status']);
expect(!empty($GLOBALS['meta'][47]['_ieb_sms_failure']), 'permanent relay rejection recorded');
ob_start(); ieb_sms_render_settings(); $failureHtml=ob_get_clean();
expect(str_contains($failureHtml,'SMS alert') && str_contains($failureHtml,'page=instant-estimate-lead&amp;id=47'), 'admin sees actionable failed lead');
expect(!str_contains(json_encode($GLOBALS['meta'][47]['_ieb_sms_failure']),'+15555550110'), 'failure diagnostics omit PII');
$GLOBALS['network_down']=true;
expect(!ieb_sms_revoke_enrollment(), 'failed remote revoke is reported');
$last=end($GLOBALS['http']); $revoke_data=json_decode($last[1]['body'],true); expect(isset($revoke_data['request_id']) && $revoke_data['request_id']===$request_body['request_id'], 'opt-out tombstones pending verification id');
expect(!ieb_sms_queue_lead(43), 'local opt-out holds during relay outage');
expect(!ieb_sms_request_enrollment('+15551230101',true), 'cannot reenroll the same phone until old revoke acknowledged');
$GLOBALS['network_down']=false;
expect(ieb_sms_revoke_enrollment(), 'opt-out retry');
expect(!ieb_sms_queue_lead(43), 'opt-out prevents later queue');
expect(strpos(json_encode($GLOBALS['options']),IEB_SMS_SECRET)===false, 'secret never stored in user state');
expect(function_exists('ieb_sms_process_admin'), 'admin request handler exists');
$GLOBALS['can']=false;
expect(!ieb_sms_process_admin(['_wpnonce'=>'valid','operation'=>'request','phone'=>'+15551230101','opt_in'=>'1']), 'handler checks capability');
$GLOBALS['can']=true;
expect(!ieb_sms_process_admin(['_wpnonce'=>'invalid','operation'=>'request','phone'=>'+15551230101','opt_in'=>'1']), 'handler checks nonce');
expect(!ieb_sms_process_admin(['_wpnonce'=>'valid','operation'=>'request','phone'=>'+15551230101']), 'handler requires opt in');
expect(function_exists('ieb_sms_render_settings'), 'admin SMS settings rendered');
ob_start(); ieb_sms_render_settings(); $html=ob_get_clean();
expect(strpos($html,IEB_SMS_SECRET)===false, 'secret not rendered in admin');
expect(strpos($html,'name="opt_in"')!==false && strpos($html,'I am the user of this number')!==false && strpos($html,'Reply HELP for help or STOP')!==false, 'own-number consent and opt out displayed');
expect(!ieb_sms_request_enrollment('+15551230101',true), 'revoked row cannot be re-enrolled without removal');
expect(ieb_sms_remove_recipient(1), 'remove acknowledged revoked row');
expect(ieb_sms_request_enrollment('+15551230101',true), 'new test enrollment');
$rows=ieb_sms_recipients(); $newId=(int)array_key_first($rows);
expect($newId!==1, 'removed id is never reused');
expect($verify->dispatch($send,time())==='accepted', 'new fake verification');
preg_match('/\b([0-9]{6})\b/',end($messages)[1],$m); $GLOBALS['confirm_race']=true; $GLOBALS['confirm_race_id']=$newId;
expect(!ieb_sms_confirm_enrollment($m[1],$newId), 'concurrent local revoke cannot be overwritten by confirmation');
expect(($GLOBALS['options'][1][ieb_sms_state_key()]['recipients'][$newId]['status'] ?? '')==='revoked', 'revoked state survives confirmation race');
expect(!ieb_sms_request_enrollment('+15551230101',true), 'new request cannot overwrite revocation state');
echo getenv('IEB_TEST_LOOPBACK_PORT') ? "PASS: loopback relay + fake provider; no external network\n" : "PASS: WP stubs + real authenticated relay and fake provider, no network\n";
