<?php
/** Twilio inbound form handler. The caller must load the official SDK. */
final class IebTwilioWebhook {
    private $queue; private $url; private $token; private $account; private $sender;
    function __construct(IebSmsQueue $queue,string $url,string $token,string $account,string $sender) {
        if (!filter_var($url,FILTER_VALIDATE_URL) || parse_url($url,PHP_URL_SCHEME)!=='https' || strlen($token)<8 || !preg_match('/\AAC[0-9a-fA-F]{32}\z/D',$account) || !preg_match('/\A\+[1-9][0-9]{7,14}\z/D',$sender)) throw new InvalidArgumentException('Invalid webhook configuration');
        $this->queue=$queue; $this->url=$url; $this->token=$token; $this->account=$account; $this->sender=$sender;
    }
    /** Return [HTTP status, TwiML]. Never log input or exceptions. */
    function handle(array $params,string $signature): array {
        $empty='<?xml version="1.0" encoding="UTF-8"?><Response></Response>';
        if (!(new Twilio\Security\RequestValidator($this->token))->validate($signature,$this->url,$params)) return [403,$empty];
        foreach (['AccountSid','To','From','MessageSid','Body'] as $key) if (!isset($params[$key]) || !is_string($params[$key])) return [400,$empty];
        if (!hash_equals($this->account,$params['AccountSid']) || !hash_equals($this->sender,$params['To']) || !preg_match('/\A\+[1-9][0-9]{7,14}\z/D',$params['From']) || !preg_match('/\ASM[0-9a-fA-F]{32}\z/D',$params['MessageSid'])) return [400,$empty];
        $opt=$params['OptOutType'] ?? ''; if (!is_string($opt)) return [400,$empty];
        $word=strtoupper(trim($params['Body']));
        if ($opt==='STOP' || in_array($word,['STOP','STOPALL','UNSUBSCRIBE','CANCEL','END','QUIT','REVOKE','OPTOUT'],true)) {
            $this->queue->webhookStop($params['From'],$params['MessageSid'],time());
        } elseif ($opt==='' && $word==='HELP') {
            return [200,'<?xml version="1.0" encoding="UTF-8"?><Response><Message>Taggart Media Group alerts. Help: support@taggartmediagroup.com. Reply STOP to opt out.</Message></Response>'];
        }
        // START intentionally does not clear STOP or create business consent.
        return [200,$empty];
    }
}
