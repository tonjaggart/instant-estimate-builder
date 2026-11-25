<?php
/** Parallel-process test helper; strictly guarded disposable WordPress and MySQL. */
$root=getenv('IEB_MULTI_TEST_WP') ?: getenv('IEB_TEST_WP');
$path=(string)realpath($root ?: ''); $multi=str_ends_with($path,'/ieb-sms-multi-recipient-test/wp');
if (!$root || (!$multi && !str_ends_with($path,'/ieb-sms-isolated-db/wp'))) throw new RuntimeException('Disposable WP required');
define('DISABLE_WP_CRON',true);
require $root.'/wp-load.php';
if ($multi ? (DB_NAME!=='ieb_multi_test' || strpos(DB_HOST,'ieb-sms-multi-recipient-test/mysql.sock')===false) : (DB_NAME!=='ieb_test' || strpos(DB_HOST,'ieb-sms-isolated-db/mysql.sock')===false)) throw new RuntimeException('Disposable DB required');
define('IEB_SMS_RELAY_HOST','relay.example.test'); define('IEB_SMS_INSTALLATION','test_install'); define('IEB_SMS_SECRET',str_repeat('a',64));
require __DIR__.'/../includes/sms-client.php';
wp_set_current_user(1); remove_all_filters('pre_schedule_event');
remove_all_filters('pre_http_request');
add_filter('pre_http_request',function($pre,$args,$url) {
    if ($url==='https://relay.example.test/v1/verification/request') {
        file_put_contents(getenv('IEB_RACE_DIR').'/requests',$args['body']."\n",FILE_APPEND|LOCK_EX);
        return ['response'=>['code'=>202],'body'=>'{"result":"requested"}','headers'=>[],'cookies'=>[]];
    }
    if ($url==='https://relay.example.test/v1/revoke') return ['response'=>['code'=>200],'body'=>'{"result":"revoked","generation_scoped_revoke":true}','headers'=>[],'cookies'=>[]];
    if ($url==='https://relay.example.test/v1/events') { file_put_contents(getenv('IEB_RACE_DIR').'/events',$args['body']."\n",FILE_APPEND|LOCK_EX); return ['response'=>['code'=>200],'body'=>'{"result":"queued"}','headers'=>[],'cookies'=>[]]; }
    throw new RuntimeException('Unexpected HTTP');
},PHP_INT_MIN,3);
$mode=$argv[1];
if ($mode==='setup') {
    delete_option(ieb_sms_state_key()); delete_option('cron');
    echo 'OK'; exit;
}
if ($mode==='rows') { echo json_encode(ieb_sms_recipients()); exit; }
if ($mode==='lead-setup') {
    $rows=ieb_sms_recipients(); foreach ($rows as &$row) $row['status']='verified'; unset($row);
    update_option(ieb_sms_state_key(),['recipients'=>$rows],false);
    $form=wp_insert_post(['post_type'=>'post','post_status'=>'draft','post_title'=>'Synthetic']);
    $lead=wp_insert_post(['post_type'=>'hgm_lead','post_status'=>'publish']);
    update_post_meta($lead,'first_name','Synthetic'); update_post_meta($lead,'phone','2025550199'); update_post_meta($lead,'_hgm_form_id',$form); echo $lead; exit;
}
if ($mode==='jobs') { echo json_encode(get_post_meta((int)$argv[2],'_ieb_sms_pending',false)); exit; }
if ($mode==='dispatch') { ieb_sms_dispatch_lead((int)$argv[2],(int)$argv[3]); exit; }
if ($mode==='remove-now') { echo ieb_sms_remove_recipient((int)$argv[2]) ? '1' : '0'; exit; }
// Prime state/meta before waiting so each process has a potentially stale cache.
get_option(ieb_sms_state_key(),false);
if ($mode==='lead') get_post_meta((int)$argv[2],'first_name',true);
$dir=getenv('IEB_RACE_DIR'); touch($dir.'/ready-'.getmypid()); $until=microtime(true)+8;
while (count(glob($dir.'/ready-*'))<2 && microtime(true)<$until) usleep(10000);
if (count(glob($dir.'/ready-*'))<2) throw new RuntimeException('Race barrier timed out');
if ($mode==='enroll') ieb_sms_request_enrollment($argv[2],true);
elseif ($mode==='lead') ieb_sms_queue_lead((int)$argv[2]);
elseif ($mode==='remove') { if (!ieb_sms_remove_recipient((int)$argv[2])) throw new RuntimeException('remove failed'); }
else throw new RuntimeException('Invalid mode');
