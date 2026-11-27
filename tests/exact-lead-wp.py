#!/usr/bin/env python3
"""Disposable WP/MySQL AJAX -> loopback signed relay -> fake SDK; no external mail or network."""
import os, pathlib, subprocess, tempfile, json, socket, time
root=pathlib.Path(__file__).resolve().parents[1]
wp=pathlib.Path(os.environ['IEB_TEST_WP']).resolve(); cli=pathlib.Path(os.environ['IEB_WP_CLI']).resolve()
if not str(wp).endswith('/ieb-sms-isolated-db/wp'): raise RuntimeError('disposable WP required')
cfg=(wp/'wp-config.php').read_text()
if "'ieb_test'" not in cfg or '/ieb-sms-isolated-db/mysql.sock' not in cfg or "'WP_HTTP_BLOCK_EXTERNAL', true" not in cfg: raise RuntimeError('unsafe fixture')
source=(root/'tests/lead-unicode-wp.php').read_text()
payload=pathlib.Path(os.environ['IEB_CAPTURE_DIR'])/'payload.json'
if not payload.is_file(): raise RuntimeError('capture actual form payload first')
old_post=next(line for line in source.splitlines() if line.strip().startswith('$_POST = wp_slash('))
source=source.replace(old_post, "    $captured=json_decode(file_get_contents(getenv('IEB_CAPTURE_DIR').'/payload.json'),true,16,JSON_THROW_ON_ERROR); $captured['nonce']=wp_create_nonce('hgm_nonce'); $captured['form_id']=(string)$form_id; $_POST=wp_slash($captured);")
source=source.replace("if (!defined('HGM_PLUGIN_PATH')) { define('HGM_PLUGIN_PATH', dirname(__DIR__) . '/'); }", "if (!defined('HGM_PLUGIN_PATH')) { define('HGM_PLUGIN_PATH', "+repr(str(root)+'/')+"); }")
setup=r'''
define('IEB_SMS_RELAY_HOST','relay.example.test'); define('IEB_SMS_INSTALLATION','test_install'); define('IEB_SMS_SECRET',str_repeat('a',64)); define('IEB_SMS_EXACT_LEAD_FORMAT',true);
require HGM_PLUGIN_PATH.'includes/sms-client.php'; require HGM_PLUGIN_PATH.'sms-relay/auth.php'; require HGM_PLUGIN_PATH.'sms-relay/queue.php'; require HGM_PLUGIN_PATH.'sms-relay/verification.php'; require HGM_PLUGIN_PATH.'sms-relay/api.php';
$db=new PDO('sqlite:'.getenv('IEB_EXACT_DB')); $key=hex2bin(getenv('IEB_EXACT_KEY')); $queue=new IebSmsQueue($db,$key); $queue->install(); ieb_auth_install_schema($db); $queue->configureInstallation('test_install',10,true);
$phones=['+12025550101','+12025550102']; $states=[];
foreach ($phones as $i=>$phone) { $queue->consent('test_install',$phone,'admin','test',time()); $epoch=$db->query('SELECT epoch FROM ieb_sms_consent_epochs ORDER BY rowid DESC LIMIT 1')->fetchColumn(); $states[$i+1]=['status'=>'verified','phone'=>$phone,'request_id'=>$epoch]; }
add_filter('pre_option__ieb_sms_recipient',fn()=>['recipients'=>$states]);
remove_all_filters('pre_http_request');
add_filter('pre_http_request',function($pre,$args,$url) { if ($url!=='https://relay.example.test/v1/events' || $args['sslverify']!==true || $args['redirection']!==0 || $args['reject_unsafe_urls']!==true) throw new RuntimeException('Outbound HTTP blocked'); $headers=[]; foreach ($args['headers'] as $k=>$v) $headers[]=$k.': '.$v; $ch=curl_init('http://127.0.0.1:'.(int)getenv('IEB_EXACT_PORT').'/v1/events'); curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$args['body'],CURLOPT_HTTPHEADER=>$headers,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>5]); $body=curl_exec($ch); $status=curl_getinfo($ch,CURLINFO_RESPONSE_CODE); if ($body===false) throw new RuntimeException('loopback failed'); return ['response'=>['code'=>$status,'message'=>'fixture'],'headers'=>[],'cookies'=>[],'body'=>$body]; },PHP_INT_MIN,3);
'''
source=source.replace("$posts = $wpdb->posts; $postmeta = $wpdb->postmeta;",setup+"\n$posts = $wpdb->posts; $postmeta = $wpdb->postmeta;")
assertion=r'''
    $pending=get_post_meta($lead_id,'_ieb_sms_pending',false); check_ieb(count($pending)===2,'two real outbox jobs');
    foreach ([1,2] as $uid) ieb_sms_dispatch_lead($lead_id,$uid);
    check_ieb(get_post_meta($lead_id,'_ieb_sms_pending',false)===[],'signed relay accepted both');
    require getenv('IEB_TEST_VENDOR').'/autoload.php'; require HGM_PLUGIN_PATH.'sms-relay/twilio.php';
    $http=new class implements Twilio\Http\Client { public $sent=[]; public function request(string $method,string $url,array $params=[],array $data=[],array $headers=[],?string $user=null,?string $password=null,?int $timeout=null,?Twilio\AuthStrategy\AuthStrategy $authStrategy=null): Twilio\Http\Response { if ($method!=='POST') throw new RuntimeException('unexpected SDK call'); $this->sent[]=[$data['To'],$data['Body']]; return new Twilio\Http\Response(200,json_encode(['sid'=>'SM'.str_repeat(count($this->sent),32)])); } };
    $sdk=new Twilio\Rest\Client('AC'.str_repeat('a',32),'fake'); $sdk->setHttpClient($http); $fake=new IebTwilioAdapter($sdk,'+12025550199');
    check_ieb($queue->dispatchOne($fake,time())==='accepted' && $queue->dispatchOne($fake,time())==='accepted','fake SDK provider dispatch');
    $expected="New __SERVICE__ Lead:\nTest\n\$7,700 - \$17,700\n(202) 555-0192\n61264";
    check_ieb($http->sent===[[$phones[0],$expected],[$phones[1],$expected]],'exact bytes and callback for both');
    update_post_meta($lead_id,'_hgm_estimate_low','$9,999');
    check_ieb(ieb_sms_exact_lead($lead_id)===false,'wrong persisted estimate rejected, not invented');
    update_post_meta($lead_id,'_hgm_estimate_low','$7,700');
    update_post_meta($lead_id,'_hgm_form_data',wp_slash(wp_json_encode(['step_1'=>['label'=>'not an option']])));
    check_ieb(ieb_sms_exact_lead($lead_id)===false,'incomplete or unrecognized selections rejected');
'''
vendor=pathlib.Path(os.environ['IEB_TEST_VENDOR']).resolve()
if not (vendor/'autoload.php').is_file(): raise RuntimeError('Twilio SDK vendor required')
# The actual front controller requires its own vendor directory. The caller must
# provide a local ignored copy/symlink, not modify a deployed relay.
if not (root/'sms-relay/vendor/autoload.php').is_file(): raise RuntimeError('local relay vendor required')
for title,service in [('Gutter Replacement','Gutter Replacement'),('Roofing Estimate','Roofing')]:
    text=source.replace("'post_title'=>'Unicode test'","'post_title'=>"+repr(title)).replace('    echo "PASS: WordPress-slashed AJAX request',assertion.replace('__SERVICE__',service)+'\n    echo "PASS: WordPress-slashed AJAX request')
    with tempfile.TemporaryDirectory(dir=os.environ['TMPDIR']) as directory:
        directory=pathlib.Path(directory); db=directory/'relay.sqlite'; db.touch(); key=os.urandom(32).hex()
        config=directory/'config.json'; config.write_text(json.dumps({'database':str(db),'encryption_key':key,'installations':{'test_install':'a'*64},'exact_lead_installations':['test_install'],'live_send_enabled':False}))
        with socket.socket() as sock: sock.bind(('127.0.0.1',0)); port=sock.getsockname()[1]
        env=dict(os.environ,IEB_RELAY_CONFIG=str(config),IEB_EXACT_DB=str(db),IEB_EXACT_KEY=key,IEB_EXACT_PORT=str(port))
        with (directory/'server.log').open('w+') as log:
            server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t','sms-relay/public','sms-relay/public/index.php'],cwd=root,env=env,stdout=log,stderr=log)
            try:
                for _ in range(100):
                    try:
                        with socket.create_connection(('127.0.0.1',port),timeout=.05): break
                    except OSError: time.sleep(.05)
                else: raise RuntimeError('loopback did not start')
                fixture=directory/'fixture.php'; fixture.write_text(text); print('FORM:',title,flush=True)
                subprocess.run(['php',str(cli),'--path='+str(wp),'--skip-plugins','--skip-themes','eval-file',str(fixture)],env=env,check=True)
            finally:
                server.terminate(); server.wait(timeout=5)
