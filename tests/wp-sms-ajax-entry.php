<?php
/** Invoke the real admin-ajax.php dispatcher using only disposable WP/MySQL. */
$root=getenv('IEB_MULTI_TEST_WP');
if (!$root || basename(dirname(realpath($root)))!=='ieb-sms-multi-recipient-test') throw new RuntimeException('Disposable WP required');
define('DISABLE_WP_CRON',true);
require $root.'/wp-load.php';
if (DB_NAME!=='ieb_multi_test' || strpos(DB_HOST,'ieb-sms-multi-recipient-test/mysql.sock')===false) throw new RuntimeException('Disposable DB required');
define('IEB_SMS_RELAY_HOST','relay.example.test'); define('IEB_SMS_INSTALLATION','test_install'); define('IEB_SMS_SECRET',str_repeat('a',64));
define('HGM_PLUGIN_PATH',dirname(__DIR__).'/');
require HGM_PLUGIN_PATH.'includes/sms-client.php';
require HGM_PLUGIN_PATH.'includes/handle-form.php';
remove_all_filters('pre_schedule_event'); remove_all_filters('pre_reschedule_event');
remove_all_filters('pre_http_request'); add_filter('pre_http_request',fn()=>new WP_Error('blocked_test_http','All HTTP blocked in disposable fixture'),PHP_INT_MIN);
remove_all_filters('pre_wp_mail'); add_filter('pre_wp_mail',function($pre,$atts) use ($argv) { file_put_contents($argv[2],json_encode($atts)."\n",FILE_APPEND|LOCK_EX); return true; },PHP_INT_MIN,2);
add_filter('pre_option_hgm_klaviyo_api_key',fn()=>'');
add_filter('pre_option_hgm_notification_settings',fn()=>['sms_recipients'=>[['phone'=>'2025550198','carrier'=>'verizon']]]);
add_filter('pre_option_hgm_email_settings',fn()=>['sales_team_emails'=>'sales@example.invalid','reply_to'=>'reply@example.invalid']);
wp_set_current_user(0);
$_SERVER['REQUEST_METHOD']='POST';
$_POST=wp_slash(['action'=>'hgm_submit_quote_form','nonce'=>wp_create_nonce('hgm_nonce'),'first_name'=>'José','email'=>'client@example.invalid','phone'=>'+12025550199','zip_code'=>'00000','form_id'=>(string)$argv[1],'_hgm_form_data'=>wp_json_encode(['step_1'=>['label'=>'Synthetic','min'=>10,'max'=>20]])]);
$_REQUEST=$_POST;
require $root.'/wp-admin/admin-ajax.php';
