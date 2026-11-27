<?php
/** Installation-authenticated API logic, independent of HTTP server. */
final class IebRelayApi {
    private $db; private $queue; private $keys; private $verification; private $exactInstallations;
    function __construct(PDO $db,IebSmsQueue $queue,array $keys,?IebSmsVerification $verification=null,array $exactInstallations=[]) { $this->db=$db; $this->queue=$queue; $this->keys=$keys; $this->verification=$verification; $this->exactInstallations=$exactInstallations; }
    function handle(string $method,string $path,array $headers,string $body,int $now): array {
        if ($method!=='POST' || !in_array($path,['/v1/consent','/v1/revoke','/v1/events','/v1/verification/request','/v1/verification/confirm'],true)) return [404,['error'=>'not_found']];
        $id=$headers['installation']??''; $key=$this->keys[$id]??'';
        if (!ieb_auth_verify($this->db,$key,$id,$method,$path,$headers['timestamp']??'', $headers['nonce']??'', $body,$headers['signature']??'',$now)) return [401,['error'=>'unauthorized']];
        try {
            $data=json_decode($body,true,16,JSON_THROW_ON_ERROR);
            if (!is_array($data)) return [400,['error'=>'invalid_request']];
            $allowed=['/v1/consent'=>['phone','actor','notice'],'/v1/revoke'=>['phone','actor'],'/v1/events'=>['event','phone','lead','request_id'],'/v1/verification/request'=>['request_id','phone','actor','notice','opt_in'],'/v1/verification/confirm'=>['request_id','code']][$path];
            $keys=array_keys($data); sort($keys); sort($allowed);
            if ($path==='/v1/revoke' && $keys===['actor','phone','request_id']) { /* optional race-safe verification tombstone */ }
            elseif ($keys!==$allowed) return [400,['error'=>'invalid_request']];
            // A signed installation request cannot establish the recipient's consent
            // or phone ownership. Keep this route closed until verification exists.
            if ($path==='/v1/consent') return [403,['error'=>'verification_required']];
            elseif ($path==='/v1/verification/request') {
                if (!$this->verification) return [503,['error'=>'unavailable']];
                if ($data['opt_in']!==true || !is_string($data['request_id']) || !is_string($data['phone']) || !is_string($data['actor']) || !is_string($data['notice'])) return [400,['error'=>'invalid_request']];
                $this->verification->request($id,$data['request_id'],$data['phone'],$data['actor'],$data['notice'],true,$now); return [202,['result'=>'requested']];
            }
            elseif ($path==='/v1/verification/confirm') {
                if (!$this->verification) return [503,['error'=>'unavailable']];
                if (!is_string($data['request_id']) || !is_string($data['code'])) return [400,['error'=>'invalid_request']];
                return $this->verification->confirm($id,$data['request_id'],$data['code'],$now) ? [200,['result'=>'verified']] : [409,['error'=>'not_allowed_or_conflict']];
            }
            elseif ($path==='/v1/revoke') { if (array_key_exists('request_id',$data) && !is_string($data['request_id'])) return [400,['error'=>'invalid_request']]; $this->queue->revoke($id,$data['phone'],$data['actor'],$now,$data['request_id']??null); $result='revoked'; }
            else { $lead=$data['lead']; if (!is_string($data['request_id']) || !is_array($lead)) return [400,['error'=>'invalid_request']]; $keys=array_keys($lead); sort($keys); if ($keys===['name','phone','service']) $lead=['name'=>$lead['name'],'phone'=>$lead['phone'],'service'=>$lead['service']]; elseif ($keys===['high','low','name','phone','service','zip'] && in_array($id,$this->exactInstallations,true)) $lead=['name'=>$lead['name'],'phone'=>$lead['phone'],'service'=>$lead['service'],'low'=>$lead['low'],'high'=>$lead['high'],'zip'=>$lead['zip']]; else return [400,['error'=>'invalid_request']]; $result=$this->queue->enqueue($id,$data['event'],$data['phone'],$lead,$now,$data['request_id']); }
            return [200,$path==='/v1/revoke' && isset($data['request_id']) ? ['result'=>$result,'generation_scoped_revoke'=>true] : ['result'=>$result]];
        } catch (DomainException $e) { return [409,['error'=>'not_allowed_or_conflict']]; }
        catch (JsonException|TypeError|InvalidArgumentException $e) { return [400,['error'=>'invalid_request']]; }
    }
}
