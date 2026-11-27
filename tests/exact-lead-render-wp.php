<?php
// Run using WP-CLI with plugins/themes skipped, against the disposable fixture.
if (!defined('WP_CLI') || !WP_CLI || DB_NAME!=='ieb_test' || strpos(DB_HOST,'ieb-sms-isolated-db/mysql.sock')===false) throw new RuntimeException('isolated WP only');
$form_id=1;
$form_data=[
 ['type'=>'dropdown','title'=>'Home size','options'=>[['label'=>'1,000–2,000 sq ft','low'=>0,'high'=>0]]],
 ['type'=>'dropdown','title'=>'Pitch','options'=>[['label'=>'Moderate (7/12–9/12)','low'=>0,'high'=>0]]],
 ['type'=>'dropdown','title'=>'Details','options'=>[['label'=>'Café — 雪 & "quotes" \\ roof','low'=>500,'high'=>1500]]],
];
ob_start(); include dirname(__DIR__).'/includes/multi-step-form.php'; $html=ob_get_clean();
// This fixture loads no third-party resources.
$html=preg_replace('~<link href="https://cdn.jsdelivr.net[^>]+>~','',$html);
$html=preg_replace('~<style>.*?</style>~s','',$html);
$html='<script src="/jquery.js"></script><script>var hgm_ajax={ajax_url:"/blocked",nonce:"fixture"};jQuery.post=function(url,data){if(data.action==="hgm_get_quote_nonce")return Promise.resolve({success:true,data:{nonce:"fixture"}});window.captured=data;return {done:function(){return {fail:function(){}}}};};</script>'.$html.'<script src="/multi-step-form.js"></script>';
if (!is_dir(getenv('IEB_CAPTURE_DIR'))) throw new RuntimeException('capture directory required');
file_put_contents(getenv('IEB_CAPTURE_DIR').'/index.html',$html);
echo "PASS: actual estimator template rendered for synthetic UI capture\n";
