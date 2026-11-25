<?php
/** Run ONLY against a disposable WP installation: IEB_TEST_WP=/path php tests/wp-sms-real-core.php [race PHONE]. */
$root=getenv('IEB_MULTI_TEST_WP') ?: getenv('IEB_TEST_WP');
$path=(string)realpath($root ?: ''); $multi=str_ends_with($path,'/ieb-sms-multi-recipient-test/wp');
if (!$root || !is_file($root.'/wp-load.php') || (!$multi && !str_ends_with($path,'/ieb-sms-isolated-db/wp'))) throw new RuntimeException('Disposable WordPress required');
define('DISABLE_WP_CRON',true);
require $root.'/wp-load.php';
if ($multi ? (DB_NAME!=='ieb_multi_test' || strpos(DB_HOST,'ieb-sms-multi-recipient-test/mysql.sock')===false) : (DB_NAME!=='ieb_test' || strpos(DB_HOST,'ieb-sms-isolated-db/mysql.sock')===false)) throw new RuntimeException('Refusing non-isolated database');
remove_all_filters('pre_schedule_event'); remove_all_filters('pre_reschedule_event');
define('IEB_SMS_RELAY_HOST','relay.example.test'); define('IEB_SMS_INSTALLATION','test_install'); define('IEB_SMS_SECRET',str_repeat('a',64));
require __DIR__.'/../includes/sms-client.php';
wp_set_current_user(1);
function check_test($ok,$why) { if (!$ok) throw new RuntimeException($why); }
remove_all_filters('pre_http_request');
add_filter('pre_http_request',function($pre,$args,$url) {
    if (!str_starts_with($url,'https://relay.example.test/v1/')) throw new RuntimeException('Unexpected outbound HTTP');
    if (getenv('IEB_RACE_LOG')) file_put_contents(getenv('IEB_RACE_LOG'),$args['body']."\n",FILE_APPEND|LOCK_EX);
    return ['response'=>['code'=>202,'message'=>'Accepted'],'body'=>'{"result":"requested"}','headers'=>[],'cookies'=>[]];
},PHP_INT_MIN,3);
if (($argv[1] ?? '')==='race' || ($argv[1] ?? '')==='lead-race') {
    // Prime metadata before synchronizing to reproduce per-process stale caches.
    if ($argv[1]==='lead-race') get_post_meta((int)$argv[2], 'first_name', true);
    $dir=getenv('IEB_RACE_DIR'); touch($dir.'/ready-'.getmypid()); $until=microtime(true)+4;
    while (count(glob($dir.'/ready-*'))<2 && microtime(true)<$until) usleep(10000);
    if ($argv[1]==='lead-race') ieb_sms_queue_lead((int)$argv[2]);
    else ieb_sms_request_enrollment($argv[2],true); exit;
}
register_post_type('hgm_lead',['public'=>true]);
global $wpdb;
$wpdb->query("DELETE FROM {$wpdb->postmeta}"); $wpdb->query("DELETE FROM {$wpdb->posts}");
delete_option('cron'); delete_option('_ieb_sms_recovery_cursor');
for ($i=0;$i<105;$i++) {
    $id=wp_insert_post(['post_type'=>'hgm_lead','post_status'=>'publish','post_title'=>'Synthetic']);
    add_post_meta($id,'_ieb_sms_pending',['uid'=>1,'created_at'=>time()]);
}
ieb_sms_recover_pending();
$events=0; foreach (_get_cron_array() as $cron) $events+=count($cron['ieb_sms_dispatch_lead'] ?? []);
check_test($events===100,'recovery must schedule at most 100 jobs per sweep, got '.$events);
$continuation=wp_next_scheduled('ieb_sms_recover_pending_batch');
check_test($continuation!==false && $continuation<=time()+10,'full recovery batch schedules prompt continuation');
ieb_sms_recover_pending();
$events=0; foreach (_get_cron_array() as $cron) $events+=count($cron['ieb_sms_dispatch_lead'] ?? []);
check_test($events===105,'recovery must advance to later jobs, got '.$events);
echo "PASS: bounded recovery advances across all pending jobs\n";
// A full intent batch must promptly continue, not wait an hour and expire the rest.
$wpdb->query("DELETE FROM {$wpdb->postmeta}"); $wpdb->query("DELETE FROM {$wpdb->posts}");
delete_option('cron'); delete_option('_ieb_sms_recovery_cursor'); delete_option('_ieb_sms_fanout_cursor');
for ($i=0;$i<101;$i++) {
    $id=wp_insert_post(['post_type'=>'hgm_lead','post_status'=>'publish','post_title'=>'Synthetic']);
    $created=time(); $job=['uid'=>1,'event'=>'fixture_'.$id,'request_id'=>str_repeat('a',32),'phone'=>'+12025550101','lead'=>['name'=>'Synthetic','phone'=>'2025550199','service'=>'Test'],'created_at'=>$created];
    add_post_meta($id,'_ieb_sms_fanout',['created_at'=>$created,'jobs'=>[$job]],true);
}
ieb_sms_recover_pending();
$left=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key='_ieb_sms_fanout'");
check_test($left===1,'intent sweep has a 100-item bound');
$continuation=wp_next_scheduled('ieb_sms_recover_pending_batch');
check_test($continuation!==false && $continuation<=time()+10,'full intent batch schedules prompt continuation');
ieb_sms_recover_pending();
$left=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key='_ieb_sms_fanout'");
check_test($left===0,'continuation can recover the remaining intent');
echo "PASS: bounded fanout recovery schedules prompt continuation\n";
$wpdb->query("DELETE FROM {$wpdb->postmeta}"); $wpdb->query("DELETE FROM {$wpdb->posts}");
delete_option('cron'); delete_option('_ieb_sms_recovery_cursor'); delete_option('_ieb_sms_fanout_cursor'); delete_option('_ieb_sms_fanout_retry_needed');
for ($i=0;$i<100;$i++) {
    $id=wp_insert_post(['post_type'=>'hgm_lead','post_status'=>'publish']);
    $created=time(); $job=['uid'=>1,'event'=>'fixture_'.$id,'request_id'=>str_repeat('a',32),'phone'=>'+12025550101','lead'=>['name'=>'Synthetic','phone'=>'2025550199','service'=>'Test'],'created_at'=>$created];
    add_post_meta($id,'_ieb_sms_fanout',['created_at'=>$created,'jobs'=>[$job]],true);
}
$block=function($check,$id,$key) { return $key==='_ieb_sms_pending' ? false : $check; };
add_filter('add_post_metadata',$block,10,3);
ieb_sms_recover_pending();
wp_clear_scheduled_hook('ieb_sms_recover_pending_batch'); // Mimic WP-Cron unscheduling before the callback.
ieb_sms_recover_pending();
$continuation=wp_next_scheduled('ieb_sms_recover_pending_batch');
check_test($continuation!==false && $continuation<=time()+65,'failed intents in prior full page must schedule a retry after an empty final page');
remove_filter('add_post_metadata',$block,10);
ieb_sms_recover_pending();
check_test((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key='_ieb_sms_fanout'")===0,'failed full batch is recoverable');
echo "PASS: failures across fanout page boundaries schedule another retry\n";

// Exercise actual WP admin/lead handlers, durable outbox, WP-Cron and real relay API,
// while all HTTP is intercepted in-process and the only provider is a closure.
delete_option('cron'); delete_option(ieb_sms_state_key());
require __DIR__.'/../sms-relay/auth.php'; require __DIR__.'/../sms-relay/queue.php'; require __DIR__.'/../sms-relay/verification.php'; require __DIR__.'/../sms-relay/api.php';
$db=new PDO('sqlite::memory:'); $key=random_bytes(32); $queue=new IebSmsQueue($db,$key); $verify=new IebSmsVerification($db,$key);
$queue->install(); ieb_auth_install_schema($db); $queue->configureInstallation(IEB_SMS_INSTALLATION,10,true);
$api=new IebRelayApi($db,$queue,[IEB_SMS_INSTALLATION=>IEB_SMS_SECRET],$verify);
$loseEventResponse=false; $forceEventRejection=false;
remove_all_filters('pre_http_request');
add_filter('pre_http_request',function($pre,$args,$url) use ($api,&$loseEventResponse,&$forceEventRejection) {
    if (!str_starts_with($url,'https://relay.example.test/v1/')) throw new RuntimeException('Outbound HTTP blocked');
    if ($forceEventRejection && str_ends_with($url,'/v1/events')) return ['response'=>['code'=>409,'message'=>'Synthetic'],'body'=>'{}','headers'=>[],'cookies'=>[]];
    $h=$args['headers']; [$status,$body]=$api->handle('POST',parse_url($url,PHP_URL_PATH),['installation'=>$h['X-Installation'],'timestamp'=>$h['X-Timestamp'],'nonce'=>$h['X-Nonce'],'signature'=>$h['X-Signature']],$args['body'],time());
    if ($loseEventResponse && str_ends_with($url,'/v1/events')) { $loseEventResponse=false; return new WP_Error('test_response_lost'); }
    return ['response'=>['code'=>$status,'message'=>'Synthetic'],'body'=>json_encode($body),'headers'=>[],'cookies'=>[]];
},PHP_INT_MIN,3);
$messages=[]; $fake=function($to,$body) use (&$messages) { $messages[]=[$to,$body]; return 'accepted'; };
$nonce=wp_create_nonce('ieb_sms_enrollment');
check_test(ieb_sms_process_admin(['_wpnonce'=>$nonce,'operation'=>'request','phone'=>'+15550000101','opt_in'=>'1']),'real admin request');
check_test($verify->dispatch($fake,time())==='accepted','fake verification provider');
check_test(preg_match('/\\b([0-9]{6})\\b/',$messages[0][1],$match)===1,'fake code');
check_test(ieb_sms_process_admin(['_wpnonce'=>$nonce,'operation'=>'confirm','recipient'=>'1','code'=>$match[1]]),'real admin confirmation');
// The configured single-recipient workflow must not expose legacy carrier controls.
define('IEB_ADMIN_MENU_SLUG','instant-estimate-builder');
require_once ABSPATH.'wp-admin/includes/template.php';
require __DIR__.'/../includes/notifications-settings.php';
$_GET['tab']='text'; ob_start(); hgm_render_notifications_settings_page(); $settingsHtml=ob_get_clean();
check_test(!str_contains($settingsHtml,'id="add-sms-recipient"'),'legacy carrier controls hidden after relay selection');
unset($_GET['tab']);
add_filter('pre_option_hgm_klaviyo_api_key',fn()=>'');
add_filter('pre_option_hgm_notification_settings',fn()=>['sms_recipients'=>[['phone'=>'2025550198','carrier'=>'verizon']]]);
add_filter('pre_option_hgm_email_settings',fn()=>['sales_team_emails'=>'sales@example.invalid','reply_to'=>'reply@example.invalid']);
$mails=[]; remove_all_filters('pre_wp_mail'); add_filter('pre_wp_mail',function($pre,$atts) use (&$mails) { $mails[]=$atts; return true; },PHP_INT_MIN,2);
add_filter('wp_die_ajax_handler',fn()=>function(){throw new RuntimeException('AJAX_DONE');});
add_filter('wp_doing_ajax',fn()=>true); define('HGM_PLUGIN_PATH',dirname(__DIR__).'/');
require HGM_PLUGIN_PATH.'includes/handle-form.php';
$form=wp_insert_post(['post_type'=>'instant_quote_form','post_title'=>'Synthetic gutters '.str_repeat('long title ',40),'post_status'=>'publish']);
update_post_meta($form,'_hgm_form_data',wp_slash(wp_json_encode([['title'=>'Size','options'=>[['label'=>'Synthetic','low'=>10,'high'=>20]]]])));
$_POST=wp_slash(['nonce'=>wp_create_nonce('hgm_nonce'),'first_name'=>'José','email'=>'client@example.invalid','phone'=>'+15550000102','zip_code'=>'00000','form_id'=>(string)$form,'_hgm_form_data'=>wp_json_encode(['step_1'=>['label'=>'Synthetic','min'=>10,'max'=>20]])]);
ob_start(); try { hgm_submit_quote_form(); throw new RuntimeException('handler failed to terminate'); } catch (RuntimeException $e) { if ($e->getMessage()!=='AJAX_DONE') throw $e; }
$response=json_decode(ob_get_clean(),true); check_test(!empty($response['success']),'actual lead AJAX succeeded');
$lead=(int)$wpdb->get_var("SELECT ID FROM {$wpdb->posts} WHERE post_type='hgm_lead' ORDER BY ID DESC LIMIT 1");
check_test(count(get_post_meta($lead,'_ieb_sms_pending',false))===1,'durable outbox saved');
check_test(count($messages)===1,'no provider dispatch in lead request');
check_test(count($mails)===2,'customer and admin emails only; legacy gateway must not send when relay is configured');
$badLead=wp_insert_post(['post_type'=>'hgm_lead','post_status'=>'publish']);
update_post_meta($badLead,'first_name','Synthetic'); update_post_meta($badLead,'phone',str_repeat('9',200)); update_post_meta($badLead,'_hgm_form_id',$form);
check_test(!ieb_sms_queue_lead($badLead),'unformattable lead rejected locally');
$failure=get_post_meta($badLead,'_ieb_sms_failure',true);
check_test(($failure['reason'] ?? '')==='format' && !str_contains(wp_json_encode($failure),str_repeat('9',20)),'safe failure stored durably');
ob_start(); ieb_sms_render_settings(); $failureHtml=ob_get_clean();
check_test(str_contains($failureHtml,'Lead #'.$badLead) && str_contains($failureHtml,'Review the lead'),'admin can find the failed lead');
$rejectLead=wp_insert_post(['post_type'=>'hgm_lead','post_status'=>'publish']);
update_post_meta($rejectLead,'first_name','Synthetic'); update_post_meta($rejectLead,'phone','+15555550103'); update_post_meta($rejectLead,'_hgm_form_id',$form);
check_test(ieb_sms_queue_lead($rejectLead),'rejection fixture queued');
$forceEventRejection=true; ieb_sms_dispatch_lead($rejectLead,1,0); $forceEventRejection=false;
$failure=get_post_meta($rejectLead,'_ieb_sms_failure',true);
check_test(($failure['reason'] ?? '')==='relay_rejected' && ($failure['http_status'] ?? 0)===409 && get_post_meta($rejectLead,'_ieb_sms_pending',false)===[],'permanent rejection recorded before outbox deletion');
ob_start(); ieb_sms_render_settings(); $failureHtml=ob_get_clean();
check_test(str_contains($failureHtml,'Lead #'.$rejectLead) && str_contains($failureHtml,'relay rejected'),'admin sees relay rejection');
$retryLead=wp_insert_post(['post_type'=>'hgm_lead','post_status'=>'publish']);
update_post_meta($retryLead,'first_name','Retry'); update_post_meta($retryLead,'phone','+15555550102'); update_post_meta($retryLead,'_hgm_form_id',$form);
check_test(ieb_sms_queue_lead($retryLead),'real MySQL retry fixture queued');
$original=get_post_meta($retryLead,'_ieb_sms_pending',false)[0];
check_test(isset($original['created_at']) && is_int($original['created_at']),'durable job has technical expiry timestamp');
$loseEventResponse=true; ieb_sms_dispatch_lead($retryLead,1,0);
check_test(get_post_meta($retryLead,'_ieb_sms_pending',false)===[$original],'lost response retains original immutable outbox');
ieb_sms_dispatch_lead($retryLead,1,1);
check_test(get_post_meta($retryLead,'_ieb_sms_pending',false)===[],'idempotent retry removes acknowledged outbox');
check_test($queue->dispatchOne($fake,time())==='accepted' && $queue->dispatchOne($fake,time())==='empty','lost response did not duplicate provider delivery');
// Let the single event become due, then run WordPress's real cron entrypoint.
$_POST=[]; remove_all_filters('wp_doing_ajax'); delete_transient('doing_cron'); wp_clear_scheduled_hook('do_pings'); sleep(2);
register_shutdown_function(function() use ($lead,$queue,$fake,&$messages) {
    try {
        check_test(count(get_post_meta($lead,'_ieb_sms_pending',false))===0,'real cron dispatched and removed outbox');
        check_test($queue->dispatchOne($fake,time())==='accepted','fake provider received cron-dispatched alert');
        check_test(count($messages)===3 && str_contains($messages[2][1],'Synthetic gutters') && str_contains($messages[2][1],'José') && str_contains($messages[2][1],'+15550000102') && ieb_sms_single_segment($messages[2][1]),'accented long-title lead retains phone in one segment');
        echo "PASS: real WP admin, lead AJAX, MySQL outbox, core WP-Cron, authenticated relay and fake provider; HTTP/mail intercepted\n";
    } catch (Throwable $e) { fwrite(STDERR,"FAIL: ".$e->getMessage()."\n"); exit(1); }
});
require $root.'/wp-cron.php';
