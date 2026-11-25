<?php
/** Disposable WP/MySQL only; no external HTTP or mail. */
$root=getenv('IEB_TEST_WP');
if (!$root || !is_file($root.'/wp-load.php') || !str_ends_with((string)realpath($root),'/ieb-sms-isolated-db/wp')) throw new RuntimeException('Disposable WordPress required');
require $root.'/wp-load.php';
if (DB_NAME !== 'ieb_test' || strpos(DB_HOST,'ieb-sms-isolated-db/mysql.sock')===false) throw new RuntimeException('Refusing non-isolated database');
define('IEB_SMS_RELAY_HOST','relay.example.test'); define('IEB_SMS_INSTALLATION','test_install'); define('IEB_SMS_SECRET',str_repeat('a',64));
require __DIR__.'/../includes/sms-client.php';
function check_retention($ok,$why) { if (!$ok) throw new RuntimeException($why); }
add_filter('pre_http_request',function(){ throw new RuntimeException('Unexpected outbound HTTP'); },PHP_INT_MIN);
register_post_type('hgm_lead',['public'=>true]);
global $wpdb;
$wpdb->query("DELETE FROM {$wpdb->postmeta}"); $wpdb->query("DELETE FROM {$wpdb->posts}");
delete_option('cron'); delete_option('_ieb_sms_recovery_cursor');
$active=wp_insert_post(['post_type'=>'hgm_lead','post_status'=>'publish']);
$trash=wp_insert_post(['post_type'=>'hgm_lead','post_status'=>'trash']);
$legacy=['uid'=>1,'phone'=>'+15555550101','lead'=>['name'=>'Legacy']];
for ($i=0;$i<102;$i++) add_post_meta($active,'_ieb_sms_pending',$legacy+['n'=>$i]);
add_post_meta($trash,'_ieb_sms_pending',['uid'=>1,'created_at'=>time()]);
$wpdb->insert($wpdb->postmeta,['post_id'=>999999999,'meta_key'=>'_ieb_sms_pending','meta_value'=>maybe_serialize(['uid'=>1,'created_at'=>time()])]);
$orphan=(int)$wpdb->insert_id;
$live=['uid'=>1,'event'=>'event1','request_id'=>str_repeat('a',32),'phone'=>'+15555550101','lead'=>['name'=>'Fresh'],'created_at'=>time()];
add_post_meta($active,'_ieb_sms_pending',$live);
// A single sweep has a hard bound, and removes legacy data rather than sending it.
ieb_sms_recover_pending();
check_retention(count(get_post_meta($active,'_ieb_sms_pending',false))===3,'first sweep must remove exactly 100 legacy rows');
check_retention(empty(_get_cron_array()) || !array_filter(_get_cron_array(),fn($v)=>isset($v['ieb_sms_dispatch_lead'])),'legacy rows must never be scheduled');
ieb_sms_recover_pending();
check_retention(get_post_meta($active,'_ieb_sms_pending',false)===[$live],'legacy removed, fresh retained');
check_retention(get_post_meta($trash,'_ieb_sms_pending',false)===[],'trashed lead outbox removed');
check_retention(get_metadata_by_mid('post',$orphan)===false,'orphan outbox removed');
$scheduled=false; foreach ((array)_get_cron_array() as $cron) if (isset($cron['ieb_sms_dispatch_lead'])) $scheduled=true;
check_retention($scheduled,'fresh job scheduled');
// Direct dispatch must not send an expired job, regardless of cron timing.
update_user_meta(1,ieb_sms_state_key(),['status'=>'verified','request_id'=>$live['request_id'],'phone'=>$live['phone']]);
check_retention(user_can(1,'manage_options'),'eligible user needed to exercise expiry fence');
delete_post_meta($active,'_ieb_sms_pending'); $old=$live; $old['created_at']=time()-3601; add_post_meta($active,'_ieb_sms_pending',$old);
ieb_sms_dispatch_lead($active,1,0);
check_retention(get_post_meta($active,'_ieb_sms_pending',false)===[],'expired direct dispatch must erase without HTTP');
echo "PASS: bounded real-MySQL outbox cleanup, legacy/trashed/expired fail closed, fresh recovery\n";
