<?php
// Exercises the actual admin-post prepare branch with synthetic state and relay only.
if ($argc===1) {
    foreach (['valid','bad_nonce','denied'] as $case) {
        passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' '.escapeshellarg($case),$result);
        if ($result!==0) exit($result);
    }
    echo "PASS: prepare redirects safely and preserves the recipient\n";
    exit;
}
function wp_create_nonce($action) { return 'valid'; }
function wp_safe_redirect($url) {
    $recipient=ieb_sms_recipients()[1] ?? null;
    if (($recipient['status'] ?? null)!=='verified' || !empty($GLOBALS['http'])) throw new RuntimeException('prepare mutated state or called relay');
    $wanted=$GLOBALS['case']==='valid' ? 'sms_confirm=1&sms_confirm_nonce=valid' : 'sms_result=error';
    if (strpos($url,$wanted)===false) throw new RuntimeException('incorrect prepare redirect');
}
ob_start(); require __DIR__.'/wp-sms-client.php'; ob_end_clean();
$GLOBALS['case']=$argv[1];
$GLOBALS['can']=$argv[1]!=='denied';
$GLOBALS['http']=[];
$GLOBALS['options'][1][ieb_sms_state_key()]=['recipients'=>[1=>['phone'=>'+120****0101','status'=>'verified']]];
$_POST=['operation'=>'remove_prepare','recipient'=>'1','_wpnonce'=>$argv[1]==='bad_nonce'?'invalid':'valid'];
ieb_sms_admin_post();
