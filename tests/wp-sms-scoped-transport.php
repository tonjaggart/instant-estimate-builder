<?php
// Behavioral hook harness; run each configuration in a fresh PHP process.
$mode=$argv[1] ?? 'valid';
const IEB_SMS_RELAY_HOST='phpstack-1239972-6805622.cloudwaysapps.com';
const IEB_SMS_INSTALLATION='synthetic_install';
define('IEB_SMS_SECRET',str_repeat('a',64));
if ($mode==='valid' || $mode==='disabledverify' || $mode==='redirects') define('IEB_SMS_RELAY_PIN','159.203.128.214');
if ($mode==='bad') define('IEB_SMS_RELAY_PIN','127.0.0.1');
$GLOBALS['hooks']=[]; $GLOBALS['calls']=0;
class FakeRequestsCurl {} class_alias('FakeRequestsCurl','WpOrg\\Requests\\Transport\\Curl');
function add_action($name,$fn,$priority=10,$args=1) { $GLOBALS['hooks'][$name][]=$fn; }
function add_filter($name,$fn,$priority=10,$args=1) { add_action($name,$fn,$priority,$args); }
function remove_action($name,$fn,$priority=10) { foreach ($GLOBALS['hooks'][$name] ?? [] as $i=>$f) if ($f===$fn) unset($GLOBALS['hooks'][$name][$i]); }
function remove_filter($name,$fn,$priority=10) { remove_action($name,$fn,$priority); }
function wp_json_encode($v) { return json_encode($v); }
function wp_safe_remote_post($url,$args) {
    ++$GLOBALS['calls'];
    $filters=$GLOBALS['hooks']['http_request_host_is_external'] ?? [];
    if (count($filters)!==1) throw new Exception('missing scoped URL safety filter');
    $f=reset($filters);
    if (!$f(false,IEB_SMS_RELAY_HOST,$url)) throw new Exception('exact route not permitted');
    foreach (['http://'.IEB_SMS_RELAY_HOST.'/v1/events','https://'.IEB_SMS_RELAY_HOST.'/v1/events?x=1','https://'.IEB_SMS_RELAY_HOST.':444/v1/events','https://127.0.0.1/v1/events','http://169.254.169.254/latest/meta-data/'] as $other) {
        if ($f(false,parse_url($other,PHP_URL_HOST),$other)!==false) throw new Exception('unsafe URL permitted');
    }
    if ($args['redirection']!==0 || !$args['sslverify'] || !$args['reject_unsafe_urls']) throw new Exception('unsafe arguments');
    $type='POST'; $headers=[]; $data=''; $options=['verify'=>$GLOBALS['mode']!=='disabledverify','verifyname'=>true,'follow_redirects'=>$GLOBALS['mode']==='redirects']; $u=$url;
    foreach ($GLOBALS['hooks']['requests-requests.before_request'] ?? [] as $hook) $hook($u,$headers,$data,$type,$options);
    if (($options['transport'] ?? '')!=='WpOrg\\Requests\\Transport\\Curl') throw new Exception('cURL not forced');
    $ch=curl_init(); foreach ($GLOBALS['hooks']['http_api_curl'] ?? [] as $hook) $hook($ch,$args,$url); unset($ch);
    if ($GLOBALS['throw'] ?? false) throw new Exception('simulated network failure');
    return ['response'=>['code'=>400]];
}
function is_wp_error($r) { return false; }
function wp_remote_retrieve_response_code($r) { return $r['response']['code']; }
require __DIR__.'/../includes/sms-client.php';
if ((!in_array($mode,['missing','bad'],true))!==ieb_sms_configured()) { fwrite(STDERR,"FAIL: configuration must reflect usable pin\n"); exit(5); }
$r=ieb_sms_post('/v1/events',[]);
if ($mode==='disabledverify' || $mode==='redirects') {
    if ($r!==0 || $GLOBALS['calls']!==1) { fwrite(STDERR,"FAIL: unsafe request options accepted\n"); exit(6); }
} elseif ($mode==='valid') {
    if ($r!==400 || $GLOBALS['calls']!==1) { fwrite(STDERR,"FAIL: scoped request not successful\n"); exit(1); }
    $GLOBALS['throw']=true; if (ieb_sms_post('/v1/events',[])!==0) exit(2);
} elseif ($r!==0 || $GLOBALS['calls']!==0) { fwrite(STDERR,"FAIL: missing or invalid pin must fail closed\n"); exit(3); }
foreach (['http_request_host_is_external','requests-requests.before_request','http_api_curl'] as $h) if (!empty($GLOBALS['hooks'][$h])) { fwrite(STDERR,"FAIL: hook leaked\n"); exit(4); }
echo "PASS scoped transport $mode\n";
