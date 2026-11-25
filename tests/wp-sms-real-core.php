<?php
/** Run ONLY against a disposable WP installation: IEB_TEST_WP=/path php tests/wp-sms-real-core.php [race PHONE]. */
$root=getenv('IEB_TEST_WP');
if (!$root || !is_file($root.'/wp-load.php') || !str_ends_with((string)realpath($root),'/ieb-sms-isolated-db/wp')) throw new RuntimeException('Disposable WordPress required');
require $root.'/wp-load.php';
if (DB_NAME !== 'ieb_test' || strpos(DB_HOST,'ieb-sms-isolated-db/mysql.sock')===false) throw new RuntimeException('Refusing non-isolated database');
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
if (($argv[1] ?? '')==='race') {
    add_filter('query',function($sql) {
        if (stripos($sql,'INSERT INTO `wp_usermeta`')!==false && strpos($sql,'_ieb_sms_enrollment_1')!==false) {
            $dir=getenv('IEB_RACE_DIR'); touch($dir.'/ready-'.getmypid()); $until=microtime(true)+2;
            while (count(glob($dir.'/ready-*'))<2 && microtime(true)<$until) usleep(10000);
        }
        return $sql;
    });
    ieb_sms_request_enrollment($argv[2],true); exit;
}
register_post_type('hgm_lead',['public'=>true]);
global $wpdb;
$wpdb->query("DELETE FROM {$wpdb->postmeta}"); $wpdb->query("DELETE FROM {$wpdb->posts}");
delete_option('cron'); delete_option('_ieb_sms_recovery_cursor');
for ($i=0;$i<105;$i++) {
    $id=wp_insert_post(['post_type'=>'hgm_lead','post_status'=>'publish','post_title'=>'Synthetic']);
    add_post_meta($id,'_ieb_sms_pending',['uid'=>1]);
}
ieb_sms_recover_pending();
$events=0; foreach (_get_cron_array() as $cron) $events+=count($cron['ieb_sms_dispatch_lead'] ?? []);
check_test($events===100,'recovery must schedule at most 100 jobs per sweep, got '.$events);
ieb_sms_recover_pending();
$events=0; foreach (_get_cron_array() as $cron) $events+=count($cron['ieb_sms_dispatch_lead'] ?? []);
check_test($events===105,'recovery must advance to later jobs, got '.$events);
echo "PASS: bounded recovery advances across all pending jobs\n";

// Exercise actual WP admin/lead handlers, durable outbox, WP-Cron and real relay API,
// while all HTTP is intercepted in-process and the only provider is a closure.
delete_option('cron'); delete_user_meta(1,ieb_sms_state_key());
require __DIR__.'/../sms-relay/auth.php'; require __DIR__.'/../sms-relay/queue.php'; require __DIR__.'/../sms-relay/verification.php'; require __DIR__.'/../sms-relay/api.php';
$db=new PDO('sqlite::memory:'); $key=random_bytes(32); $queue=new IebSmsQueue($db,$key); $verify=new IebSmsVerification($db,$key);
$queue->install(); ieb_auth_install_schema($db); $queue->configureInstallation(IEB_SMS_INSTALLATION,10,true);
$api=new IebRelayApi($db,$queue,[IEB_SMS_INSTALLATION=>IEB_SMS_SECRET],$verify);
remove_all_filters('pre_http_request');
add_filter('pre_http_request',function($pre,$args,$url) use ($api) {
    if (!str_starts_with($url,'https://relay.example.test/v1/')) throw new RuntimeException('Outbound HTTP blocked');
    $h=$args['headers']; [$status,$body]=$api->handle('POST',parse_url($url,PHP_URL_PATH),['installation'=>$h['X-Installation'],'timestamp'=>$h['X-Timestamp'],'nonce'=>$h['X-Nonce'],'signature'=>$h['X-Signature']],$args['body'],time());
    return ['response'=>['code'=>$status,'message'=>'Synthetic'],'body'=>json_encode($body),'headers'=>[],'cookies'=>[]];
},PHP_INT_MIN,3);
$messages=[]; $fake=function($to,$body) use (&$messages) { $messages[]=[$to,$body]; return 'accepted'; };
$nonce=wp_create_nonce('ieb_sms_enrollment');
check_test(ieb_sms_process_admin(['_wpnonce'=>$nonce,'operation'=>'request','phone'=>'+15550000101','opt_in'=>'1']),'real admin request');
check_test($verify->dispatch($fake,time())==='accepted','fake verification provider');
check_test(preg_match('/\\b([0-9]{6})\\b/',$messages[0][1],$match)===1,'fake code');
check_test(ieb_sms_process_admin(['_wpnonce'=>$nonce,'operation'=>'confirm','code'=>$match[1]]),'real admin confirmation');
add_filter('pre_option_hgm_klaviyo_api_key',fn()=>'');
add_filter('pre_option_hgm_notification_settings',fn()=>['sms_recipients'=>[]]);
add_filter('pre_option_hgm_email_settings',fn()=>['sales_team_emails'=>'sales@example.invalid','reply_to'=>'reply@example.invalid']);
$mails=[]; remove_all_filters('pre_wp_mail'); add_filter('pre_wp_mail',function($pre,$atts) use (&$mails) { $mails[]=$atts; return true; },PHP_INT_MIN,2);
add_filter('wp_die_ajax_handler',fn()=>function(){throw new RuntimeException('AJAX_DONE');});
add_filter('wp_doing_ajax',fn()=>true); define('HGM_PLUGIN_PATH',dirname(__DIR__).'/');
require HGM_PLUGIN_PATH.'includes/handle-form.php';
$form=wp_insert_post(['post_type'=>'instant_quote_form','post_title'=>'Synthetic gutters','post_status'=>'publish']);
update_post_meta($form,'_hgm_form_data',wp_slash(wp_json_encode([['title'=>'Size','options'=>[['label'=>'Synthetic','low'=>10,'high'=>20]]]])));
$_POST=wp_slash(['nonce'=>wp_create_nonce('hgm_nonce'),'first_name'=>'Synthetic','email'=>'client@example.invalid','phone'=>'+15550000102','zip_code'=>'00000','form_id'=>(string)$form,'_hgm_form_data'=>wp_json_encode(['step_1'=>['label'=>'Synthetic','min'=>10,'max'=>20]])]);
ob_start(); try { hgm_submit_quote_form(); throw new RuntimeException('handler failed to terminate'); } catch (RuntimeException $e) { if ($e->getMessage()!=='AJAX_DONE') throw $e; }
$response=json_decode(ob_get_clean(),true); check_test(!empty($response['success']),'actual lead AJAX succeeded');
$lead=(int)$wpdb->get_var("SELECT ID FROM {$wpdb->posts} WHERE post_type='hgm_lead' ORDER BY ID DESC LIMIT 1");
check_test(count(get_post_meta($lead,'_ieb_sms_pending',false))===1,'durable outbox saved');
check_test(count($messages)===1,'no provider dispatch in lead request');
check_test(count($mails)===2,'customer and admin emails were intercepted');
// Let the single event become due, then run WordPress's real cron entrypoint.
$_POST=[]; remove_all_filters('wp_doing_ajax'); sleep(2);
register_shutdown_function(function() use ($lead,$queue,$fake,&$messages) {
    try {
        check_test(count(get_post_meta($lead,'_ieb_sms_pending',false))===0,'real cron dispatched and removed outbox');
        check_test($queue->dispatchOne($fake,time())==='accepted','fake provider received cron-dispatched alert');
        check_test(count($messages)===2 && str_contains($messages[1][1],'Synthetic gutters'),'expected synthetic lead message');
        echo "PASS: real WP admin, lead AJAX, MySQL outbox, core WP-Cron, authenticated relay and fake provider; HTTP/mail intercepted\n";
    } catch (Throwable $e) { fwrite(STDERR,"FAIL: ".$e->getMessage()."\n"); exit(1); }
});
require $root.'/wp-cron.php';
