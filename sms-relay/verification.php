<?php
/** Verification service. The provider is injected; this class never chooses a live sender. */
final class IebSmsVerification {
    private $db; private $key; private $hashKey;
    function __construct(PDO $db,string $key) {
        if (strlen($key)!==SODIUM_CRYPTO_SECRETBOX_KEYBYTES) throw new InvalidArgumentException('Invalid key');
        $this->db=$db; $this->key=$key; $this->hashKey=hash_hmac('sha256','ieb-sms-index-v1',$key,true);
        $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION); $db->exec('PRAGMA busy_timeout=5000');
    }
    private function query(string $sql,array $args=[]) { $s=$this->db->prepare($sql); $s->execute($args); return $s; }
    private function atomic(callable $fn) { $this->db->exec('BEGIN IMMEDIATE'); try { $r=$fn(); $this->db->exec('COMMIT'); return $r; } catch (Throwable $e) { $this->db->exec('ROLLBACK'); throw $e; } }
    private function validId(string $s): bool { return (bool)preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/D',$s); }
    private function digest(string $id,string $requestId,string $code): string { return hash_hmac('sha256',"ieb-verification-v1\n$id\n$requestId\n$code",$this->hashKey); }
    function request(string $id,string $requestId,string $phone,string $actor,string $notice,bool $optIn,int $now): void {
        if (!$this->validId($id) || !$this->validId($actor) || !preg_match('/\A[0-9a-f]{32}\z/D',$requestId) || !preg_match('/\A\+[1-9][0-9]{7,14}\z/D',$phone) || !in_array($notice,['admin-sms-v1','admin-sms-v2'],true) || !$optIn) throw new InvalidArgumentException('Invalid request');
        $hash=hash_hmac('sha256',$phone,$this->hashKey);
        $this->atomic(function() use($id,$requestId,$phone,$actor,$notice,$hash,$now) {
            if ($this->query('SELECT 1 FROM ieb_sms_verification_revocations WHERE installation=? AND request_id=?',[$id,$requestId])->fetchColumn()) return;
            $old=$this->query('SELECT phone_hash,actor,notice,request_fingerprint FROM ieb_sms_verifications WHERE installation=? AND request_id=?',[$id,$requestId])->fetch(PDO::FETCH_ASSOC);
            if ($old) {
                if ($old['request_fingerprint']!==null) {
                    $fingerprint=hash_hmac('sha256',json_encode([$hash,$actor,$notice],JSON_THROW_ON_ERROR),$this->hashKey);
                    if (!hash_equals($old['request_fingerprint'],$fingerprint)) throw new DomainException('Conflict');
                } elseif (!hash_equals($old['phone_hash'],$hash) || $old['actor']!==$actor || $old['notice']!==$notice) throw new DomainException('Conflict');
                return;
            }
            $countPhone=(int)$this->query('SELECT count(*) FROM ieb_sms_verifications WHERE phone_hash=? AND created_at>?',[$hash,$now-3600])->fetchColumn();
            $countInstall=(int)$this->query('SELECT count(*) FROM ieb_sms_verifications WHERE installation=? AND created_at>?',[$id,$now-3600])->fetchColumn();
            $countGlobal=(int)$this->query('SELECT count(*) FROM ieb_sms_verifications WHERE created_at>?',[$now-3600])->fetchColumn();
            $enabled=(bool)$this->query('SELECT 1 FROM ieb_sms_installations WHERE id=? AND enabled=1',[$id])->fetchColumn();
            $stopped=(bool)$this->query('SELECT stopped FROM ieb_sms_suppression WHERE phone_hash=?',[$hash])->fetchColumn();
            // Reject before inserting: blocked requests must not create unlimited rows.
            if (!$enabled || $stopped || $countPhone>=3 || $countInstall>=20 || $countGlobal>=200) return;
            $state='queued';
            $code=str_pad((string)random_int(0,999999),6,'0',STR_PAD_LEFT); $digest=$this->digest($id,$requestId,$code); $cipher='';
            if ($state==='queued') { $nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES); $cipher=base64_encode($nonce.sodium_crypto_secretbox(json_encode(['phone'=>$phone,'code'=>$code],JSON_THROW_ON_ERROR),$nonce,$this->key)); }
            $this->query('INSERT INTO ieb_sms_verifications (installation,request_id,phone_hash,actor,notice,code_hash,ciphertext,state,attempts,created_at,expires_at) VALUES (?,?,?,?,?,?,?,?,0,?,?)',[$id,$requestId,$hash,$actor,$notice,$digest,$cipher,$state,$now,$now+600]);
        });
    }
    function confirm(string $id,string $requestId,string $code,int $now): bool {
        if (!$this->validId($id) || !preg_match('/\A[0-9a-f]{32}\z/D',$requestId) || !preg_match('/\A[0-9]{6}\z/D',$code)) throw new InvalidArgumentException('Invalid request');
        return $this->atomic(function() use($id,$requestId,$code,$now) {
            $row=$this->query('SELECT * FROM ieb_sms_verifications WHERE installation=? AND request_id=?',[$id,$requestId])->fetch(PDO::FETCH_ASSOC);
            if (!$row || !in_array($row['state'],['unknown','accepted','verified'],true) || $now >= (int)$row['expires_at'] || (int)$row['attempts']>=5) return false;
            $enabled=$this->query('SELECT 1 FROM ieb_sms_installations WHERE id=? AND enabled=1',[$id])->fetchColumn();
            $stopped=$this->query('SELECT stopped FROM ieb_sms_suppression WHERE phone_hash=?',[$row['phone_hash']])->fetchColumn();
            if (!$enabled || $stopped) return false;
            if ($row['state']==='verified') {
                $current=$this->query('SELECT c.epoch FROM ieb_sms_consent_epochs c JOIN ieb_sms_consents s ON s.installation=c.installation AND s.phone_hash=c.phone_hash WHERE c.installation=? AND c.phone_hash=? AND s.active=1',[$id,$row['phone_hash']])->fetchColumn();
                if ($current!==$requestId) return false;
                if (!hash_equals($row['code_hash'],$this->digest($id,$requestId,$code))) {
                    $this->query('UPDATE ieb_sms_verifications SET attempts=attempts+1 WHERE installation=? AND request_id=?',[$id,$requestId]);
                    return false;
                }
                return true;
            }
            if (!hash_equals($row['code_hash'],$this->digest($id,$requestId,$code))) { $this->query('UPDATE ieb_sms_verifications SET attempts=attempts+1 WHERE installation=? AND request_id=?',[$id,$requestId]); return false; }
            $this->query("UPDATE ieb_sms_verifications SET state='verified',ciphertext='' WHERE installation=? AND request_id=?",[$id,$requestId]);
            $this->query('INSERT INTO ieb_sms_consents VALUES (?,?,?,?,?,1) ON CONFLICT(installation,phone_hash) DO UPDATE SET actor=excluded.actor,notice=excluded.notice,consented_at=excluded.consented_at,active=1',[$id,$row['phone_hash'],$row['actor'],$row['notice'],$now]);
            $this->query('INSERT INTO ieb_sms_consent_epochs VALUES (?,?,?) ON CONFLICT(installation,phone_hash) DO UPDATE SET epoch=excluded.epoch',[$id,$row['phone_hash'],$requestId]);
            $this->query('INSERT INTO ieb_sms_consent_audit (installation,phone_hash,actor,notice,action,occurred_at) VALUES (?,?,?,?,?,?)',[$id,$row['phone_hash'],$row['actor'],$row['notice'],'verified-consent',$now]);
            return true;
        });
    }
    /** Claim as uncertain and erase queued ciphertext before contacting the provider. */
    function dispatch(callable $provider,int $now): string {
        $row=$this->atomic(function() use($now) {
            $this->query("UPDATE ieb_sms_verifications SET state='expired',ciphertext='' WHERE rowid IN (SELECT rowid FROM ieb_sms_verifications WHERE state='queued' AND expires_at<=? ORDER BY expires_at,rowid LIMIT 100)",[$now]);
            $row=$this->query("SELECT * FROM ieb_sms_verifications WHERE state='queued' AND expires_at>? ORDER BY created_at,rowid LIMIT 1",[$now])->fetch(PDO::FETCH_ASSOC);
            if (!$row) return false;
            $month=gmdate('Y-m',$now);
            $used=(int)$this->query('SELECT COUNT(*) FROM ieb_sms_verification_budget WHERE month=? AND installation=?',[$month,$row['installation']])->fetchColumn();
            $global=(int)$this->query('SELECT COUNT(*) FROM ieb_sms_verification_budget WHERE month=?',[$month])->fetchColumn();
            if ($used>=20 || $global>=200) {
                $this->query("UPDATE ieb_sms_verifications SET state='capped',ciphertext='' WHERE installation=? AND request_id=?",[$row['installation'],$row['request_id']]);
                return 'capped';
            }
            // Reserve before the provider call; a crash or cancellation cannot refund uncertainty.
            $this->query('INSERT INTO ieb_sms_verification_budget (installation,request_id,month) VALUES (?,?,?)',[$row['installation'],$row['request_id'],$month]);
            $this->query("UPDATE ieb_sms_verifications SET state='unknown',ciphertext='' WHERE installation=? AND request_id=?",[$row['installation'],$row['request_id']]); return $row;
        });
        if ($row==='capped') return 'capped';
        if (!$row) return 'empty';
        $raw=base64_decode($row['ciphertext'],true);
        if ($raw===false || strlen($raw)<SODIUM_CRYPTO_SECRETBOX_NONCEBYTES+SODIUM_CRYPTO_SECRETBOX_MACBYTES) return 'unknown';
        $plain=sodium_crypto_secretbox_open(substr($raw,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),substr($raw,0,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),$this->key);
        if ($plain===false) return 'unknown'; $data=json_decode($plain,true);
        return $this->atomic(function() use($row,$data,$provider,$now) {
            $current=$this->query('SELECT state FROM ieb_sms_verifications WHERE installation=? AND request_id=?',[$row['installation'],$row['request_id']])->fetchColumn();
            $enabled=$this->query('SELECT 1 FROM ieb_sms_installations WHERE id=? AND enabled=1',[$row['installation']])->fetchColumn();
            $stopped=$this->query('SELECT stopped FROM ieb_sms_suppression WHERE phone_hash=?',[$row['phone_hash']])->fetchColumn();
            if ($current!=='unknown' || !$enabled || $stopped || $now >= (int)$row['expires_at'] || !is_array($data) || !isset($data['phone'],$data['code'])) { $this->query("UPDATE ieb_sms_verifications SET state='cancelled' WHERE installation=? AND request_id=? AND state='unknown'",[$row['installation'],$row['request_id']]); return 'suppressed'; }
            try { $result=$provider($data['phone'],'Taggart Media Group code: '.$data['code'].'. Enter to opt in to estimate alerts. STOP to opt out. HELP: support@taggartmediagroup.com.'); } catch (Throwable $e) { $result='unknown'; }
            if (is_array($result)) $result=(($result['state']??null)==='accepted' && is_string($result['sid']??null) && preg_match('/\ASM[0-9a-fA-F]{32}\z/D',$result['sid'])) ? 'accepted' : 'unknown';
            if ($result==='accepted') $this->query("UPDATE ieb_sms_verifications SET state='accepted' WHERE installation=? AND request_id=? AND state='unknown'",[$row['installation'],$row['request_id']]);
            return $result==='accepted' ? 'accepted' : 'unknown';
        });
    }
}
