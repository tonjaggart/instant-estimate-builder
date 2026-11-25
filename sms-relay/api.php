<?php
/** Installation-authenticated API logic, independent of HTTP server. */
final class IebRelayApi {
    private $db; private $queue; private $keys;
    function __construct(PDO $db,IebSmsQueue $queue,array $keys) { $this->db=$db; $this->queue=$queue; $this->keys=$keys; }
    function handle(string $method,string $path,array $headers,string $body,int $now): array {
        if ($method!=='POST' || !in_array($path,['/v1/consent','/v1/revoke','/v1/events'],true)) return [404,['error'=>'not_found']];
        $id=$headers['installation']??''; $key=$this->keys[$id]??'';
        if (!ieb_auth_verify($this->db,$key,$id,$method,$path,$headers['timestamp']??'', $headers['nonce']??'', $body,$headers['signature']??'',$now)) return [401,['error'=>'unauthorized']];
        try {
            $data=json_decode($body,true,16,JSON_THROW_ON_ERROR);
            if (!is_array($data)) return [400,['error'=>'invalid_request']];
            $allowed=['/v1/consent'=>['phone','actor','notice'],'/v1/revoke'=>['phone','actor'],'/v1/events'=>['event','phone','lead']][$path];
            $keys=array_keys($data); sort($keys); sort($allowed); if ($keys!==$allowed) return [400,['error'=>'invalid_request']];
            // A signed installation request cannot establish the recipient's consent
            // or phone ownership. Keep this route closed until verification exists.
            if ($path==='/v1/consent') return [403,['error'=>'verification_required']];
            elseif ($path==='/v1/revoke') { $this->queue->revoke($id,$data['phone'],$data['actor'],$now); $result='revoked'; }
            else { $lead=$data['lead']; if (!is_array($lead) || count($lead)!==3 || !isset($lead['name'],$lead['phone'],$lead['service'])) return [400,['error'=>'invalid_request']]; $result=$this->queue->enqueue($id,$data['event'],$data['phone'],['name'=>$lead['name'],'phone'=>$lead['phone'],'service'=>$lead['service']],$now); }
            return [200,['result'=>$result]];
        } catch (DomainException $e) { return [409,['error'=>'not_allowed_or_conflict']]; }
        catch (JsonException|TypeError $e) { return [400,['error'=>'invalid_request']]; }
    }
}
