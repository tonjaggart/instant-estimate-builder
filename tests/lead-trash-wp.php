<?php
/** Run only against the network-isolated, disposable WordPress/MySQL fixture. */
$root = getenv('IEB_TEST_WP');
if (!$root || realpath($root) !== realpath('/Users/jack/.hermes/cache/scratch/ieb-sms-isolated-db/wp')) throw new RuntimeException('Disposable fixture required');
if (getenv('IEB_TEST_TRASH_DISABLED') === '1') define('EMPTY_TRASH_DAYS', 0);
require $root . '/wp-load.php';
if (DB_NAME !== 'ieb_test' || strpos(DB_HOST, 'ieb-sms-isolated-db/mysql.sock') === false || !defined('WP_HTTP_BLOCK_EXTERNAL') || !WP_HTTP_BLOCK_EXTERNAL) throw new RuntimeException('Unsafe database or network configuration');
require __DIR__ . '/../instant-estimate-builder.php';
hgm_register_cpts();
function check_lead_trash($condition, $message) { if (!$condition) throw new RuntimeException($message); }
add_filter('pre_http_request', function () { throw new RuntimeException('Unexpected HTTP'); }, PHP_INT_MIN);
add_filter('pre_wp_mail', function () { throw new RuntimeException('Unexpected mail'); }, PHP_INT_MIN);
$admin = wp_insert_user(['user_login'=>'trash-test-admin-'.uniqid(), 'user_pass'=>wp_generate_password(), 'role'=>'administrator']);
$editor = wp_insert_user(['user_login'=>'trash-test-editor-'.uniqid(), 'user_pass'=>wp_generate_password(), 'role'=>'editor']);
check_lead_trash(!is_wp_error($admin) && !is_wp_error($editor), 'fixture users');
$ids = [];
try {
    $lead = wp_insert_post(['post_type'=>'hgm_lead','post_status'=>'publish','post_title'=>'Synthetic lead','post_author'=>$editor]); $ids[]=$lead;
    $other = wp_insert_post(['post_type'=>'hgm_lead','post_status'=>'publish','post_title'=>'Other lead','post_author'=>$admin]); $ids[]=$other;
    $nonlead_id = wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'Not a lead']); $ids[]=$nonlead_id;
    $attachment = wp_insert_post(['post_type'=>'attachment','post_status'=>'inherit','post_title'=>'Not a lead']); $ids[]=$attachment;
    update_post_meta($lead, 'phone', 'synthetic'); update_post_meta($other, 'phone', 'preserve');
    wp_set_current_user($admin); $nonce=wp_create_nonce('ieb_trash_lead_'.$lead);
    check_lead_trash(function_exists('ieb_trash_lead'), 'trash implementation missing');
    if (getenv('IEB_TEST_TRASH_DISABLED') === '1') {
        $disabled = ieb_trash_lead($lead, $nonce, 'POST');
        check_lead_trash(is_wp_error($disabled) && $disabled->get_error_code()==='trash_disabled' && get_post_status($lead)==='publish', 'disabled trash must never permanently delete');
        echo "PASS: disabled Trash fails closed, preserving the lead\n";
        return;
    }
    $_GET=['confirm_lead'=>(string)$lead];
    ob_start(); ieb_render_leads_page(); $rendered=ob_get_clean(); $_GET=[];
    check_lead_trash(strpos($rendered,'Move lead to Trash?')!==false && strpos($rendered,'name="action" value="ieb_trash_lead"')!==false && strpos($rendered,'>Cancel</a>')!==false && strpos($rendered,'View Lead Details')!==false, 'confirmation, cancellation and normal lead view render');
    check_lead_trash(is_wp_error(ieb_trash_lead($lead, $nonce, 'GET')), 'GET must not delete');
    check_lead_trash(is_wp_error(ieb_trash_lead($lead, 'bad', 'POST')), 'bad nonce must fail');
    check_lead_trash(is_wp_error(ieb_trash_lead($nonlead_id, wp_create_nonce('ieb_trash_lead_'.$nonlead_id), 'POST')), 'page must not be deleted');
    check_lead_trash(is_wp_error(ieb_trash_lead($attachment, wp_create_nonce('ieb_trash_lead_'.$attachment), 'POST')), 'attachment must not be deleted');
    wp_set_current_user($editor);
    check_lead_trash(is_wp_error(ieb_trash_lead($lead, wp_create_nonce('ieb_trash_lead_'.$lead), 'POST')), 'editor must not delete');
    wp_set_current_user($admin);
    check_lead_trash(get_post_status($lead)==='publish' && get_post_status($nonlead_id)==='publish' && get_post($attachment)->post_status==='inherit', 'rejected requests preserve records');
    check_lead_trash(ieb_trash_lead($lead, $nonce, 'POST') === true, 'admin must trash lead owned by another user');
    check_lead_trash(get_post_status($lead)==='trash' && get_post_meta($lead,'phone',true)==='synthetic', 'lead and metadata remain recoverable');
    check_lead_trash(get_post_status($other)==='publish' && get_post_meta($other,'phone',true)==='preserve', 'other lead intact');
    check_lead_trash(is_wp_error(ieb_trash_lead($lead, $nonce, 'POST')), 'repeat request must not permanently delete');
    check_lead_trash(get_post_status($lead)==='trash', 'repeat preserves trash');
    $listed=get_posts(['post_type'=>'hgm_lead','post_status'=>'publish','numberposts'=>-1,'fields'=>'ids']);
    check_lead_trash(!in_array($lead,$listed,true) && in_array($other,$listed,true), 'list excludes trashed lead, keeps other');
    check_lead_trash((bool)wp_untrash_post($lead) && get_post_status($lead)!=='trash', 'lead can be restored');
    echo "PASS: permissions, nonce, POST, wrong post type, recoverability, repeated action, listing, cross-owner and no outbound mail/HTTP\n";
} finally {
    foreach ($ids as $id) wp_delete_post($id,true);
    require_once ABSPATH.'wp-admin/includes/user.php'; wp_delete_user($admin); wp_delete_user($editor);
}
