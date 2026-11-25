<?php
if (!is_file(__DIR__.'/../sms-relay/twilio.php')) throw new RuntimeException('Twilio adapter missing');
require __DIR__.'/../sms-relay/vendor/autoload.php'; require __DIR__.'/../sms-relay/twilio.php'; require __DIR__.'/../sms-relay/queue.php';
$port=(int)getenv('IEB_FAKE_PORT');
$client=new Twilio\Rest\Client('AC'.str_repeat('a',32),'fake-token');
$client->setHttpClient(new Twilio\Http\CurlClient([CURLOPT_URL=>'http://127.0.0.1:'.$port.'/fake',CURLOPT_TIMEOUT=>2,CURLOPT_CONNECTTIMEOUT=>1]));
$adapter=new IebTwilioAdapter($client,'+12025550100');
if (getenv('IEB_FAKE_EXISTING_DB')==='1') {
    $config=json_decode(file_get_contents(getenv('IEB_RELAY_CONFIG')),true,16,JSON_THROW_ON_ERROR);
    $db=new PDO('sqlite:'.$config['database']); $q=new IebSmsQueue($db,hex2bin($config['encryption_key']));
} else {
    $db=new PDO('sqlite::memory:'); $q=new IebSmsQueue($db,random_bytes(32)); $q->install(); $q->configureInstallation('install_123',5,true);
    $q->consent('install_123','+12025550101','admin_1','notice-v1',time());
    $q->enqueue('install_123','event_1','+12025550101',['name'=>'Synthetic Name','phone'=>'+12025550102','service'=>'Test'],time());
}
echo $q->dispatchOne($adapter,time());
