<?php
/** Run only against the isolated disposable WordPress/MySQL fixture. */
$root = getenv('IEB_TEST_WP');
if (!$root || realpath($root) !== realpath('/Users/jack/.hermes/cache/scratch/ieb-sms-isolated-db/wp')) throw new RuntimeException('Disposable fixture required');
if (getenv('IEB_TEST_TRASH_DISABLED') === '1') define('EMPTY_TRASH_DAYS', 0);
require $root . '/wp-load.php';
if (DB_NAME !== 'ieb_test' || strpos(DB_HOST, 'ieb-sms-isolated-db/mysql.sock') === false || !defined('WP_HTTP_BLOCK_EXTERNAL') || !WP_HTTP_BLOCK_EXTERNAL) throw new RuntimeException('Unsafe fixture');
require __DIR__ . '/../instant-estimate-builder.php'; hgm_register_cpts();
function bulk_check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
add_filter('pre_http_request', function () { throw new RuntimeException('Unexpected HTTP'); }, PHP_INT_MIN);
add_filter('pre_wp_mail', function () { throw new RuntimeException('Unexpected mail'); }, PHP_INT_MIN);
$admin=wp_insert_user(['user_login'=>'bulk-admin-'.uniqid(),'user_pass'=>wp_generate_password(),'role'=>'administrator']);
$editor=wp_insert_user(['user_login'=>'bulk-editor-'.uniqid(),'user_pass'=>wp_generate_password(),'role'=>'editor']);
$ids=[];
try {
    foreach (['One','Two','Three'] as $name) { $ids[]=wp_insert_post(['post_type'=>'hgm_lead','post_status'=>'publish','post_title'=>'Synthetic '.$name,'post_author'=>$editor]); }
    [$one,$two,$three]=$ids;
    $nonlead_id=wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'Not a lead']); $ids[]=$nonlead_id;
    update_post_meta($one,'phone','synthetic'); update_post_meta($three,'keep','unchanged');
    wp_set_current_user($admin); $nonce=wp_create_nonce('ieb_bulk_trash_leads');
    bulk_check(function_exists('ieb_bulk_trash_leads'), 'bulk implementation missing');
    if (getenv('IEB_TEST_TRASH_DISABLED') === '1') {
        $result=ieb_bulk_trash_leads([$one,$two],$nonce,'POST');
        bulk_check(is_wp_error($result) && $result->get_error_code()==='trash_disabled' && get_post_status($one)==='publish' && get_post_status($two)==='publish', 'disabled Trash must not delete');
        echo "PASS: disabled Trash fails closed\n"; return;
    }
    $_GET=[]; ob_start(); ieb_render_leads_page(); $html=ob_get_clean();
    bulk_check(strpos($html,'name="lead_ids[]"')!==false && strpos($html,'ieb-select-all-leads')!==false && strpos($html,'View Lead Details')!==false && strpos($html,'confirm_lead=')===false, 'bulk checkboxes and details replace per-lead trash');
    bulk_check(is_wp_error(ieb_bulk_trash_leads([$one],$nonce,'GET')), 'GET rejected');
    bulk_check(is_wp_error(ieb_bulk_trash_leads([$one],'bad','POST')), 'bad nonce rejected');
    bulk_check(is_wp_error(ieb_bulk_trash_leads([$one,$nonlead_id],$nonce,'POST')), 'mixed types rejected before any action');
    bulk_check(is_wp_error(ieb_bulk_trash_leads([$one,$one],$nonce,'POST')), 'duplicates rejected');
    bulk_check(is_wp_error(ieb_bulk_trash_leads([$one,999999999],$nonce,'POST')), 'missing lead rejected before any action');
    wp_set_current_user($editor); bulk_check(is_wp_error(ieb_bulk_trash_leads([$one],wp_create_nonce('ieb_bulk_trash_leads'),'POST')), 'editor rejected'); wp_set_current_user($admin);
    bulk_check(get_post_status($one)==='publish' && get_post_status($nonlead_id)==='publish', 'invalid request changes nothing: lead='.var_export(get_post_status($one),true).' page='.var_export(get_post_status($nonlead_id),true));
    $result=ieb_bulk_trash_leads([$one,$two],$nonce,'POST');
    bulk_check(is_array($result) && $result['trashed']===2 && $result['failed']===0, 'two selected leads trashed');
    bulk_check(get_post_status($one)==='trash' && get_post_status($two)==='trash' && get_post_meta($one,'phone',true)==='synthetic', 'selected leads recoverable and metadata preserved');
    bulk_check(get_post_status($three)==='publish' && get_post_meta($three,'keep',true)==='unchanged' && get_post_status($nonlead_id)==='publish', 'unselected records preserved');
    bulk_check(is_wp_error(ieb_bulk_trash_leads([$one],$nonce,'POST')) && get_post_status($one)==='trash', 'repeat cannot permanently delete');
    $block=function($check,$post) use ($three) { return $post->ID===$three ? false : $check; }; add_filter('pre_trash_post',$block,10,2);
    $result=ieb_bulk_trash_leads([$three],$nonce,'POST'); remove_filter('pre_trash_post',$block,10);
    bulk_check(is_array($result) && $result['trashed']===0 && $result['failed']===1 && get_post_status($three)==='publish', 'failure count honest');
    bulk_check((bool)wp_untrash_post($one), 'can restore');
    wp_update_post(['ID'=>$one,'post_status'=>'publish']);
    $race=function($id) use ($one,$three) { if ($id===$one) wp_update_post(['ID'=>$three,'post_status'=>'draft']); };
    add_action('trashed_post',$race,10,1);
    $result=ieb_bulk_trash_leads([$one,$three],$nonce,'POST');
    remove_action('trashed_post',$race,10);
    bulk_check(is_array($result) && $result['trashed']===1 && $result['failed']===1 && get_post_status($three)==='draft', 'status changed after validation is skipped and counted');
    echo "PASS: bulk selection, permissions, POST, nonce, mixed types, duplicates, missing IDs, failures, status race, recovery and blocked outbound\n";
} finally { foreach ($ids as $id) wp_delete_post($id,true); require_once ABSPATH.'wp-admin/includes/user.php'; wp_delete_user($admin); wp_delete_user($editor); }
