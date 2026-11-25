<?php
/** Disposable, socket-only WordPress/MySQL. HTTP and mail never leave this process. */
$root=getenv('IEB_MULTI_TEST_WP');
if (!$root || basename(dirname(realpath($root)))!=='ieb-sms-multi-recipient-test') throw new RuntimeException('Disposable WP required');
define('DISABLE_WP_CRON',true);
require $root.'/wp-load.php';
if (DB_NAME!=='ieb_multi_test' || strpos(DB_HOST,'ieb-sms-multi-recipient-test/mysql.sock')===false) throw new RuntimeException('Disposable DB required');
define('IEB_SMS_RELAY_HOST','relay.example.test'); define('IEB_SMS_INSTALLATION','test_install'); define('IEB_SMS_SECRET',str_repeat('a',64));
require __DIR__.'/../includes/sms-client.php';
require __DIR__.'/../sms-relay/auth.php'; require __DIR__.'/../sms-relay/queue.php'; require __DIR__.'/../sms-relay/verification.php'; require __DIR__.'/../sms-relay/api.php';
function check_multi($ok,$message) { if (!$ok) throw new RuntimeException($message); }
wp_set_current_user(1);
// This fixture blocks cron by default; allow scheduling only in this disposable process.
remove_all_filters('pre_schedule_event'); remove_all_filters('pre_reschedule_event');
$db=new PDO('sqlite::memory:'); $key=random_bytes(32); $queue=new IebSmsQueue($db,$key); $verify=new IebSmsVerification($db,$key);
$queue->install(); ieb_auth_install_schema($db); $queue->configureInstallation(IEB_SMS_INSTALLATION,100,true);
$api=new IebRelayApi($db,$queue,[IEB_SMS_INSTALLATION=>IEB_SMS_SECRET],$verify);
remove_all_filters('pre_http_request');
add_filter('pre_http_request',function($pre,$args,$url) use ($api) {
    if (!str_starts_with($url,'https://relay.example.test/v1/')) throw new RuntimeException('Unexpected outbound HTTP');
    $h=$args['headers']; [$status,$body]=$api->handle('POST',parse_url($url,PHP_URL_PATH),['installation'=>$h['X-Installation'],'timestamp'=>$h['X-Timestamp'],'nonce'=>$h['X-Nonce'],'signature'=>$h['X-Signature']],$args['body'],time());
    return ['response'=>['code'=>$status,'message'=>'Synthetic'],'body'=>json_encode($body),'headers'=>[],'cookies'=>[]];
},PHP_INT_MIN,3);
$messages=[]; $fake=function($phone,$body) use (&$messages) { $messages[]=[$phone,$body]; return 'accepted'; };
delete_option(ieb_sms_state_key()); delete_option('cron');
$interest=['opt_in'=>true,'phone'=>'2025550191','disclosure_version'=>'prelaunch-1'];
$legacy=['sms_recipients'=>[['phone'=>'2025550191','carrier'=>'verizon']]];
update_option('ieb_planned_sms_interest',$interest,false); update_option('hgm_notification_settings',$legacy,false);
check_multi(ieb_sms_recipients()===[],'prelaunch preferences never become consent');
$prior=['status'=>'verified','phone'=>'+12025550110','request_id'=>str_repeat('d',32),'actor'=>'admin_1'];
update_option(ieb_sms_state_key(),$prior,false);
check_multi(ieb_sms_recipients()===[1=>$prior],'existing verified single recipient remains accessible');
check_multi(ieb_sms_change_recipient(1,$prior,$prior) && get_option(ieb_sms_state_key())===['recipients'=>[1=>$prior]],'single recipient migration retains its generation');
check_multi(get_option('ieb_planned_sms_interest')===$interest && get_option('hgm_notification_settings')===$legacy,'legacy preferences preserved');
delete_option(ieb_sms_state_key());
$nonce=wp_create_nonce('ieb_sms_enrollment');
wp_set_current_user(0);
check_multi(!ieb_sms_process_admin(['_wpnonce'=>$nonce,'operation'=>'request','phone'=>'+12025550109','opt_in'=>'1']),'unauthorized user cannot enroll');
wp_set_current_user(1);
$phones=['+12025550101','+12025550102','+12025550103'];
foreach ($phones as $index=>$phone) {
    check_multi(ieb_sms_process_admin(['_wpnonce'=>$nonce,'operation'=>'request','phone'=>$phone,'opt_in'=>'1']), 'request recipient '.($index+1));
    check_multi($verify->dispatch($fake,time())==='accepted','verification sent to fake provider');
    if ($index===0) check_multi(ieb_sms_process_admin(['_wpnonce'=>$nonce,'operation'=>'request','phone'=>$phones[1],'opt_in'=>'1']), 'a second distinct number can be pending independently');
    if ($index===2) continue;
    preg_match('/\b([0-9]{6})\b/',end($messages)[1],$m);
    $rows=ieb_sms_recipients(); $id=null;
    foreach ($rows as $key=>$row) if ($row['phone']===$phone) $id=(string)$key;
    check_multi($id!==null,'recipient has stable ID');
    check_multi(ieb_sms_process_admin(['_wpnonce'=>$nonce,'operation'=>'confirm','recipient'=>$id,'code'=>$m[1]]),'confirm individual number');
}
echo "PASS: independently enrolled two numbers and retained third pending\n";
register_post_type('hgm_lead',['public'=>true]);
$form=wp_insert_post(['post_type'=>'post','post_title'=>'Synthetic gutters','post_status'=>'publish']);
$lead=wp_insert_post(['post_type'=>'hgm_lead','post_status'=>'publish']);
update_post_meta($lead,'first_name','Synthetic'); update_post_meta($lead,'phone','2025550199'); update_post_meta($lead,'_hgm_form_id',$form);
$secondId=null; foreach (ieb_sms_recipients() as $id=>$row) if ($row['phone']===$phones[1]) $secondId=(int)$id;
$injectFailure=function($check,$objectId,$key,$value) use ($secondId) {
    if ($key==='_ieb_sms_pending' && ($value['uid'] ?? null)===$secondId) return false;
    return $check;
};
add_filter('add_post_metadata',$injectFailure,10,4);
ieb_sms_queue_lead($lead);
check_multi(count(get_post_meta($lead,'_ieb_sms_pending',false))===1,'one recipient job was intentionally rejected by storage');
check_multi(count(get_post_meta($lead,'_ieb_sms_fanout',false))===1,'partial fanout retains a durable intent');
$initialRetry=wp_next_scheduled('ieb_sms_recover_failed_fanout');
check_multi($initialRetry!==false && $initialRetry<=time()+65,'initial partial failure schedules prompt recovery');
$fanout=get_post_meta($lead,'_ieb_sms_fanout',true);
check_multi(count($fanout['jobs'])===2,'intent snapshots exactly the verified recipients');
$failed=get_post_meta($lead,'_ieb_sms_failure',true);
check_multi(($failed['reason'] ?? '')==='fanout_failed','partial failure is visible to administrators');
ieb_sms_recover_pending();
check_multi(count(get_post_meta($lead,'_ieb_sms_fanout',false))===1,'intent survives a repeated insert failure');
$retry=wp_next_scheduled('ieb_sms_recover_pending_batch');
check_multi($retry!==false && $retry<=time()+65,'partial failure schedules another attempt inside expiry window');
remove_filter('add_post_metadata',$injectFailure,10);
ieb_sms_recover_pending();
$jobs=get_post_meta($lead,'_ieb_sms_pending',false);
check_multi(count($jobs)===2,'recovery creates missing recipient job without duplicating another');
check_multi(get_post_meta($lead,'_ieb_sms_fanout',false)===[],'completed intent removes lead data');
check_multi(!ieb_sms_queue_lead($lead),'completed fanout cannot be reopened');
check_multi($jobs[0]['event']!==$jobs[1]['event'],'per-recipient event IDs differ');
foreach ($jobs as $job) ieb_sms_dispatch_lead($lead,$job['uid']);
check_multi($queue->dispatchOne($fake,time())==='accepted' && $queue->dispatchOne($fake,time())==='accepted' && $queue->dispatchOne($fake,time())==='empty','two accepted provider messages');
$tos=array_column(array_slice($messages,3),0); sort($tos); $expected=array_slice($phones,0,2); sort($expected);
check_multi($tos===$expected,'both verified numbers, not pending, received the alert');
echo "PASS: distinct durable jobs and fake provider alerts\n";
check_multi(!ieb_sms_process_admin(['_wpnonce'=>'wrong','operation'=>'revoke','recipient'=>(string)$jobs[0]['uid']]),'nonce required');
check_multi(!ieb_sms_process_admin(['_wpnonce'=>$nonce,'operation'=>'confirm','recipient'=>'not-an-id','code'=>'123456']),'recipient ID must be valid');
check_multi(!ieb_sms_request_enrollment('(202) 555-0101',true),'normalized duplicate verified number rejected');
require_once ABSPATH.'wp-admin/includes/template.php';
ob_start(); ieb_sms_render_settings(); $html=ob_get_clean();
foreach ($phones as $phone) check_multi(str_contains($html,$phone),'UI shows each number');
check_multi(substr_count($html,'name="recipient"')===4,'per-recipient controls carry a row ID');
check_multi(str_contains($html,'name="opt_in"'),'new number needs its own consent');
echo "PASS: secured multi-recipient UI and normalized duplicate handling\n";
// A queued alert must not revive after the same person opts out and opts in again.
$firstId=$jobs[0]['uid']; foreach ($jobs as $job) if ($job['phone']===$phones[0]) $firstId=$job['uid'];
$oldLead=wp_insert_post(['post_type'=>'hgm_lead','post_status'=>'publish']);
update_post_meta($oldLead,'first_name','Fence'); update_post_meta($oldLead,'phone','2025550199'); update_post_meta($oldLead,'_hgm_form_id',$form);
check_multi(ieb_sms_queue_lead($oldLead),'generation fence fixture queued');
$oldJob=null; foreach (get_post_meta($oldLead,'_ieb_sms_pending',false) as $job) if ($job['uid']===$firstId) $oldJob=$job;
check_multi(is_array($oldJob),'first recipient has old generation job');
check_multi(ieb_sms_process_admin(['_wpnonce'=>$nonce,'operation'=>'revoke','recipient'=>(string)$firstId]),'first recipient opted out');
check_multi(ieb_sms_request_enrollment($phones[0],true),'first recipient requests new generation');
check_multi($verify->dispatch($fake,time())==='accepted','new verification reached fake provider');
preg_match('/\b([0-9]{6})\b/',end($messages)[1],$code);
check_multi(ieb_sms_process_admin(['_wpnonce'=>$nonce,'operation'=>'confirm','recipient'=>(string)$firstId,'code'=>$code[1]]),'first recipient confirms new generation');
check_multi(ieb_sms_recipients()[$firstId]['request_id']!==$oldJob['request_id'],'new consent has new generation');
ieb_sms_dispatch_lead($oldLead,$firstId);
check_multi(count(get_post_meta($oldLead,'_ieb_sms_pending',false))===1,'old generation discarded, other recipient remains');
ieb_sms_dispatch_lead($oldLead,$jobs[1]['phone']===$phones[1] ? $jobs[1]['uid'] : $jobs[0]['uid']);
check_multi($queue->dispatchOne($fake,time())==='accepted' && $queue->dispatchOne($fake,time())==='empty','only other recipient receives old lead');
echo "PASS: revocation and re-enrollment cannot revive old generation\n";
// Exercise the actual AJAX handler, both notifications, and core WP-Cron.
$form=wp_insert_post(['post_type'=>'instant_quote_form','post_title'=>'Synthetic gutters','post_status'=>'publish']);
update_post_meta($form,'_hgm_form_data',wp_slash(wp_json_encode([['title'=>'Size','options'=>[['label'=>'Synthetic','low'=>10,'high'=>20]]]])));
$mailFile=tempnam(dirname($root),'synthetic-mail-');
$command=[PHP_BINARY,'-d','error_reporting=24575',__DIR__.'/wp-sms-ajax-entry.php',(string)$form,$mailFile];
$process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
check_multi(is_resource($process),'AJAX dispatcher process started'); fclose($pipes[0]);
$body=stream_get_contents($pipes[1]); fclose($pipes[1]); $errors=stream_get_contents($pipes[2]); fclose($pipes[2]);
$status=proc_close($process); $mails=file($mailFile,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES); unlink($mailFile);
check_multi($status===0,'AJAX dispatcher exited successfully: '.$errors);
$response=json_decode($body,true); check_multi(!empty($response['success']),'real admin-ajax.php dispatcher succeeded');
// Refresh parent process caches after the separate AJAX request.
wp_cache_delete('cron','options'); wp_cache_delete('alloptions','options'); wp_cache_delete('notoptions','options');
global $wpdb; $ajaxLead=(int)$wpdb->get_var("SELECT ID FROM {$wpdb->posts} WHERE post_type='hgm_lead' ORDER BY ID DESC LIMIT 1");
check_multi(count(get_post_meta($ajaxLead,'_ieb_sms_pending',false))===2,'AJAX enqueued two jobs');
check_multi(count($mails)===2,'customer and admin email remain independent, no carrier gateway email');
$_POST=[]; remove_all_filters('wp_doing_ajax'); delete_transient('doing_cron'); wp_clear_scheduled_hook('do_pings'); sleep(2);
register_shutdown_function(function() use ($ajaxLead,$queue,$fake,&$messages,$phones,$form) {
    try {
        check_multi(count(get_post_meta($ajaxLead,'_ieb_sms_pending',false))===0,'WP-Cron dispatched both jobs');
        $before=count($messages);
        check_multi($queue->dispatchOne($fake,time())==='accepted' && $queue->dispatchOne($fake,time())==='accepted' && $queue->dispatchOne($fake,time())==='empty','both cron-dispatched alerts reached fake provider');
        $alerts=array_slice($messages,$before); $tos=array_column($alerts,0); sort($tos); $expected=array_slice($phones,0,2); sort($expected);
        check_multi($tos===$expected && str_contains($alerts[0][1],'José') && str_contains($alerts[1][1],'José'),'both verified recipients received actual lead, pending third did not');
        // STOP one number; the other still receives a later alert even when the first is rejected.
        $queue->stop($phones[0],time());
        $stopLead=wp_insert_post(['post_type'=>'hgm_lead','post_status'=>'publish']);
        update_post_meta($stopLead,'first_name','Stop test'); update_post_meta($stopLead,'phone','2025550199'); update_post_meta($stopLead,'_hgm_form_id',$form);
        check_multi(ieb_sms_queue_lead($stopLead),'STOP isolation fixture queued');
        foreach (get_post_meta($stopLead,'_ieb_sms_pending',false) as $job) ieb_sms_dispatch_lead($stopLead,$job['uid']);
        $failure=get_post_meta($stopLead,'_ieb_sms_failure',true);
        check_multi(($failure['reason'] ?? '')==='relay_rejected' && ($failure['http_status'] ?? 0)===409,'stopped recipient rejection recorded');
        check_multi($queue->dispatchOne($fake,time())==='accepted' && $queue->dispatchOne($fake,time())==='empty','STOP suppresses only stopped number');
        check_multi(end($messages)[0]===$phones[1],'other recipient received the later alert');
        echo "PASS: actual AJAX, both emails, WP-Cron to two numbers, pending third ignored, STOP isolation, signed relay and fake provider\n";
    } catch (Throwable $e) { fwrite(STDERR,'FAIL: '.$e->getMessage()."\n"); exit(1); }
});
require $root.'/wp-cron.php';
