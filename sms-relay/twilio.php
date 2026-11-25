<?php
/** SDK sender. All failures are uncertain: there is no automatic retry. */
final class IebTwilioAdapter {
    private $client; private $from;
    function __construct(Twilio\Rest\Client $client,string $from) {
        if (!preg_match('/\A\+[1-9][0-9]{7,14}\z/D',$from)) throw new InvalidArgumentException('Invalid sender');
        $this->client=$client; $this->from=$from;
    }
    function __invoke(string $to,string $message) {
        try {
            $result=$this->client->messages->create($to,['from'=>$this->from,'body'=>$message]);
            return is_string($result->sid) && preg_match('/\ASM[0-9a-fA-F]{32}\z/D',$result->sid) ? ['state'=>'accepted','sid'=>$result->sid] : 'unknown';
        } catch (Throwable $e) { return 'unknown'; }
    }
}
/** Retrieve a known Message SID without emitting provider data. */
final class IebTwilioStatus {
    private $client;
    function __construct(Twilio\Rest\Client $client) { $this->client=$client; }
    function __invoke(string $sid): ?string {
        if (!preg_match('/\ASM[0-9a-fA-F]{32}\z/D',$sid)) return null;
        $message=$this->client->messages($sid)->fetch();
        return is_string($message->status) ? $message->status : null;
    }
}
