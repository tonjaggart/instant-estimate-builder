<?php
// Test-only sender: force the actual SDK to a loopback fake, never Twilio.
require __DIR__.'/../sms-relay/vendor/autoload.php'; require __DIR__.'/../sms-relay/twilio.php'; require __DIR__.'/../sms-relay/verification.php';
$c=json_decode(file_get_contents(getenv('IEB_RELAY_CONFIG')),true,16,JSON_THROW_ON_ERROR);
$db=new PDO('sqlite:'.$c['database']); $v=new IebSmsVerification($db,hex2bin($c['encryption_key']));
$client=new Twilio\Rest\Client('AC'.str_repeat('a',32),'synthetic-token');
$client->setHttpClient(new Twilio\Http\CurlClient([CURLOPT_URL=>'http://127.0.0.1:'.(int)getenv('IEB_FAKE_PORT').'/fake',CURLOPT_CONNECTTIMEOUT=>1,CURLOPT_TIMEOUT=>3]));
echo $v->dispatch(new IebTwilioAdapter($client,'+15550000100'),time());
