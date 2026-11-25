<?php
/** SDK sender. All failures are uncertain: there is no automatic retry. */
final class IebTwilioAdapter {
    private $client; private $from;
    function __construct(Twilio\Rest\Client $client,string $from) {
        if (!preg_match('/\A\+[1-9][0-9]{7,14}\z/D',$from)) throw new InvalidArgumentException('Invalid sender');
        $this->client=$client; $this->from=$from;
    }
    function __invoke(string $to,string $message): string {
        try {
            $result=$this->client->messages->create($to,['from'=>$this->from,'body'=>$message]);
            return is_string($result->sid) && preg_match('/\ASM[0-9a-fA-F]{32}\z/D',$result->sid) ? 'accepted' : 'unknown';
        } catch (Throwable $e) { return 'unknown'; }
    }
}
