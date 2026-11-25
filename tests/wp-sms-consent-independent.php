<?php
/** Recipient consent is independent of WordPress role. Disposable WP and fake provider only. */
$root=getenv('IEB_TEST_WP');
if (!$root || !is_file($root.'/wp-load.php') || !str_ends_with((string)realpath($root),'/ieb-sms-isolated-db/wp')) throw new RuntimeException('Disposable WordPress required');
require $root.'/wp-load.php';
if (DB_NAME!=='ieb_test' || strpos(DB_HOST,'ieb-sms-isolated-db/mysql.sock')===false) throw new RuntimeException('Refusing non-isolated database');
require_once ABSPATH.'wp-admin/includes/user.php';
require __DIR__.'/../includes/sms-client.php';
require __DIR__.'/../sms-relay/queue.php';
add_filter('pre_http_request',function(){ throw new RuntimeException('Outbound HTTP blocked'); },PHP_INT_MIN);
$db=new PDO('sqlite::memory:'); $queue=new IebSmsQueue($db,random_bytes(32)); $queue->install(); $queue->configureInstallation('test_install',10,true);
$sent=0; $fake=function() use (&$sent){ ++$sent; return 'accepted'; };
foreach (['demote','delete'] as $action) {
    $login='sms_fixture_'.$action.'_'.bin2hex(random_bytes(4));
    $uid=wp_insert_user(['user_login'=>$login,'user_pass'=>wp_generate_password(),'role'=>'administrator']);
    if (is_wp_error($uid)) throw new RuntimeException('Fixture creation failed');
    $phone=$action==='demote' ? '+15555550111' : '+15555550112';
    $queue->consent('test_install',$phone,'admin_'.$uid,'test_notice',time());
    $queue->enqueue('test_install','lead_'.$action,$phone,['name'=>'Synthetic','phone'=>'+15555550113','service'=>'Synthetic'],time());
    // WordPress cannot reach the relay during this local role change.
    if ($action==='demote') { $user=new WP_User($uid); $user->set_role('subscriber'); }
    else wp_delete_user($uid);
    if ($queue->dispatchOne($fake,time())!=='accepted') throw new RuntimeException('Gap reproduction changed; review blocker');
    if ($action==='demote') wp_delete_user($uid);
}
if ($sent!==2) throw new RuntimeException('Gap reproduction changed');
echo "PASS: fake-provider dispatch follows recipient consent, not WordPress user lifecycle\n";
