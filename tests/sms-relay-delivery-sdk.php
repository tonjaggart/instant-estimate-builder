<?php
require __DIR__.'/../sms-relay/vendor/autoload.php';
require __DIR__.'/../sms-relay/twilio.php';
class FakeHttp implements Twilio\Http\Client {
    public $requests=[]; public $status='delivered';
    public function request(string $method,string $url,array $params=[],array $data=[],array $headers=[],?string $user=null,?string $password=null,?int $timeout=null,?Twilio\AuthStrategy\AuthStrategy $authStrategy=null): Twilio\Http\Response {
        $this->requests[]=[$method,$url]; return new Twilio\Http\Response(200,json_encode(['sid'=>'SM'.str_repeat('a',32),'status'=>$this->status]));
    }
}
$client=new Twilio\Rest\Client('AC'.str_repeat('a',32),'fake-token'); $http=new FakeHttp(); $client->setHttpClient($http);
$lookup=new IebTwilioStatus($client); $sid='SM'.str_repeat('a',32);
if ($lookup($sid)!=='delivered' || count($http->requests)!==1 || $http->requests[0][0]!=='GET' || strpos($http->requests[0][1],$sid)===false) throw new RuntimeException('fake SDK lookup failed');
echo "PASS fake SDK delivery status lookup; no network\n";
