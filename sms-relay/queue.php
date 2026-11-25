<?php
/** Encrypted SQLite queue for the separately hosted relay; not a WordPress component. */
final class IebSmsQueue {
    private $db; private $key; private $hashKey;
    function __construct(PDO $db, string $key) {
        if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) throw new InvalidArgumentException('Invalid key');
        $this->db = $db; $this->key = $key;
        $this->hashKey = hash_hmac('sha256', 'ieb-sms-index-v1', $key, true);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA busy_timeout=5000');
    }
    function install(): void {
        $this->db->exec('CREATE TABLE IF NOT EXISTS ieb_sms_installations (id TEXT PRIMARY KEY, monthly_cap INTEGER NOT NULL, enabled INTEGER NOT NULL)');
        $this->db->exec('CREATE TABLE IF NOT EXISTS ieb_sms_consents (installation TEXT NOT NULL, phone_hash TEXT NOT NULL, actor TEXT NOT NULL, notice TEXT NOT NULL, consented_at INTEGER NOT NULL, active INTEGER NOT NULL, PRIMARY KEY(installation,phone_hash))');
        $this->db->exec('CREATE TABLE IF NOT EXISTS ieb_sms_suppression (phone_hash TEXT PRIMARY KEY, stopped INTEGER NOT NULL, changed_at INTEGER NOT NULL)');
        $this->db->exec('CREATE TABLE IF NOT EXISTS ieb_sms_webhook_stops (sid_hash TEXT PRIMARY KEY)');
        $this->db->exec('CREATE TABLE IF NOT EXISTS ieb_sms_consent_audit (id INTEGER PRIMARY KEY AUTOINCREMENT, installation TEXT NOT NULL, phone_hash TEXT NOT NULL, actor TEXT NOT NULL, notice TEXT NOT NULL, action TEXT NOT NULL, occurred_at INTEGER NOT NULL)');
        $this->db->exec('CREATE TABLE IF NOT EXISTS ieb_sms_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, installation TEXT NOT NULL, event TEXT NOT NULL, phone_hash TEXT NOT NULL, payload_hash TEXT NOT NULL, ciphertext TEXT NOT NULL, state TEXT NOT NULL, attempts INTEGER NOT NULL DEFAULT 0, due_at INTEGER NOT NULL, quota_month TEXT, UNIQUE(installation,event,phone_hash))');
    }
    private function checkId($id): void { if (!is_string($id) || !preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/D', $id)) throw new DomainException('Invalid identifier'); }
    private function phash($phone): string {
        if (!is_string($phone) || !preg_match('/\A\+[1-9][0-9]{7,14}\z/D', $phone)) throw new DomainException('Invalid phone');
        return hash_hmac('sha256', $phone, $this->hashKey);
    }
    private function query($sql, $args = []) { $s = $this->db->prepare($sql); $s->execute($args); return $s; }
    private function atomic(callable $fn) {
        $this->db->exec('BEGIN IMMEDIATE');
        try { $result = $fn(); $this->db->exec('COMMIT'); return $result; }
        catch (Throwable $e) { $this->db->exec('ROLLBACK'); throw $e; }
    }
    function configureInstallation(string $id, int $cap, bool $enabled): void {
        $this->checkId($id); if ($cap < 1) throw new DomainException('Cap required');
        $this->query('INSERT INTO ieb_sms_installations VALUES (?,?,?) ON CONFLICT(id) DO UPDATE SET monthly_cap=excluded.monthly_cap,enabled=excluded.enabled', [$id,$cap,(int)$enabled]);
    }
    function consent(string $id, string $phone, string $actor, string $notice, int $now): bool {
        $this->checkId($id); $this->checkId($actor); $this->checkId($notice); $hash = $this->phash($phone);
        return $this->atomic(function() use ($id,$actor,$notice,$now,$hash) {
            if (!$this->query('SELECT id FROM ieb_sms_installations WHERE id=?',[$id])->fetchColumn()) throw new DomainException('Unknown installation');
            if ($this->query('SELECT stopped FROM ieb_sms_suppression WHERE phone_hash=?',[$hash])->fetchColumn()) return false;
            $this->query('INSERT INTO ieb_sms_consents VALUES (?,?,?,?,?,1) ON CONFLICT(installation,phone_hash) DO UPDATE SET actor=excluded.actor, notice=excluded.notice, consented_at=excluded.consented_at, active=1',[$id,$hash,$actor,$notice,$now]);
            $this->query('INSERT INTO ieb_sms_consent_audit (installation,phone_hash,actor,notice,action,occurred_at) VALUES (?,?,?,?,?,?)',[$id,$hash,$actor,$notice,'consent',$now]);
            return true;
        });
    }
    function revoke(string $id, string $phone, string $actor, int $now): void {
        $this->checkId($id); $this->checkId($actor); $hash=$this->phash($phone);
        $this->atomic(function() use($id,$hash,$actor,$now) {
            $this->query('UPDATE ieb_sms_consents SET active=0 WHERE installation=? AND phone_hash=?',[$id,$hash]);
            $this->query('INSERT INTO ieb_sms_consent_audit (installation,phone_hash,actor,notice,action,occurred_at) VALUES (?,?,?,?,?,?)',[$id,$hash,$actor,'','revoke',$now]);
        });
    }
    function stop(string $phone, int $now): void {
        $hash=$this->phash($phone);
        $this->atomic(function() use($hash,$now) {
            $this->query('INSERT INTO ieb_sms_suppression VALUES (?,1,?) ON CONFLICT(phone_hash) DO UPDATE SET stopped=1,changed_at=excluded.changed_at',[$hash,$now]);
            $this->query('UPDATE ieb_sms_consents SET active=0 WHERE phone_hash=?',[$hash]);
            $this->query('INSERT INTO ieb_sms_consent_audit (installation,phone_hash,actor,notice,action,occurred_at) VALUES (?,?,?,?,?,?)',['',$hash,'provider','','stop',$now]);
        });
    }
    function webhookStop(string $phone, string $sid, int $now): void {
        $hash=$this->phash($phone);
        if (!preg_match('/\ASM[0-9a-fA-F]{32}\z/D',$sid)) throw new DomainException('Invalid SID');
        $sidHash=hash_hmac('sha256',$sid,$this->hashKey);
        $this->atomic(function() use($hash,$sidHash,$now) {
            $insert=$this->query('INSERT OR IGNORE INTO ieb_sms_webhook_stops VALUES (?)',[$sidHash]);
            if (!$insert->rowCount()) return;
            $this->query('INSERT INTO ieb_sms_suppression VALUES (?,1,?) ON CONFLICT(phone_hash) DO UPDATE SET stopped=1,changed_at=excluded.changed_at',[$hash,$now]);
            $this->query('UPDATE ieb_sms_consents SET active=0 WHERE phone_hash=?',[$hash]);
            $this->query('INSERT INTO ieb_sms_consent_audit (installation,phone_hash,actor,notice,action,occurred_at) VALUES (?,?,?,?,?,?)',['',$hash,'provider','','stop',$now]);
        });
    }
    function start(string $phone, int $now): void {
        $hash=$this->phash($phone);
        $this->query('INSERT INTO ieb_sms_suppression VALUES (?,0,?) ON CONFLICT(phone_hash) DO UPDATE SET stopped=0,changed_at=excluded.changed_at WHERE excluded.changed_at > ieb_sms_suppression.changed_at',[$hash,$now]);
    }
    private function allowed($id,$hash): bool {
        return (bool)$this->query('SELECT 1 FROM ieb_sms_installations i JOIN ieb_sms_consents c ON c.installation=i.id LEFT JOIN ieb_sms_suppression s ON s.phone_hash=c.phone_hash WHERE i.id=? AND c.phone_hash=? AND i.enabled=1 AND c.active=1 AND COALESCE(s.stopped,0)=0',[$id,$hash])->fetchColumn();
    }
    function enqueue(string $id, string $event, string $phone, array $lead, int $now): string {
        $this->checkId($id); $this->checkId($event); $hash=$this->phash($phone);
        if (array_keys($lead) !== ['name','phone','service']) throw new DomainException('Invalid lead');
        foreach ($lead as $value) if (!is_string($value) || trim($value)==='' || strlen($value)>160 || preg_match('/[\x00-\x1f\x7f]/', $value)) throw new DomainException('Invalid lead');
        $payload=json_encode(['recipient'=>$phone,'lead'=>$lead], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $payloadHash=hash_hmac('sha256',$payload,$this->hashKey);
        return $this->atomic(function() use ($id,$event,$hash,$payload,$payloadHash,$now) {
            $existing=$this->query('SELECT payload_hash FROM ieb_sms_messages WHERE installation=? AND event=? AND phone_hash=?',[$id,$event,$hash])->fetchColumn();
            if ($existing !== false) { if (!hash_equals($existing,$payloadHash)) throw new DomainException('Event conflict'); return 'duplicate'; }
            if (!$this->allowed($id,$hash)) throw new DomainException('Not allowed');
            $nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $cipher=base64_encode($nonce.sodium_crypto_secretbox($payload,$nonce,$this->key));
            $this->query('INSERT INTO ieb_sms_messages (installation,event,phone_hash,payload_hash,ciphertext,state,due_at) VALUES (?,?,?,?,?,\'queued\',?)',[$id,$event,$hash,$payloadHash,$cipher,$now]);
            return 'queued';
        });
    }
    /** 'retryable' requires proven non-acceptance; Twilio adapter never returns it. */
    function dispatchOne(callable $provider, int $now): string {
        $claim=$this->atomic(function() use($now) {
            $row=$this->query("SELECT * FROM ieb_sms_messages WHERE state IN ('queued','retry') AND due_at<=? ORDER BY id LIMIT 1",[$now])->fetch(PDO::FETCH_ASSOC);
            if (!$row) return ['result'=>'empty'];
            if (!$this->allowed($row['installation'],$row['phone_hash'])) {
                $this->query("UPDATE ieb_sms_messages SET state='suppressed' WHERE id=?",[$row['id']]); return ['result'=>'suppressed'];
            }
            $month=gmdate('Y-m',$now);
            $cap=(int)$this->query('SELECT monthly_cap FROM ieb_sms_installations WHERE id=?',[$row['installation']])->fetchColumn();
            $used=(int)$this->query("SELECT COUNT(*) FROM ieb_sms_messages WHERE installation=? AND quota_month=? AND state IN ('unknown','accepted')",[$row['installation'],$month])->fetchColumn();
            if ($used >= $cap) { $this->query("UPDATE ieb_sms_messages SET state='capped' WHERE id=?",[$row['id']]); return ['result'=>'capped']; }
            // Mark uncertain and reserve quota BEFORE calling provider. A crash cannot trigger an automatic retry.
            $this->query("UPDATE ieb_sms_messages SET state='unknown', quota_month=?, attempts=attempts+1 WHERE id=?",[$month,$row['id']]);
            return ['result'=>'claimed','row'=>$row];
        });
        if ($claim['result'] !== 'claimed') return $claim['result'];
        $row=$claim['row'];
        $raw=base64_decode($row['ciphertext'],true);
        if ($raw===false || strlen($raw)<SODIUM_CRYPTO_SECRETBOX_NONCEBYTES+SODIUM_CRYPTO_SECRETBOX_MACBYTES) return 'unknown';
        $plain=sodium_crypto_secretbox_open(substr($raw,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),substr($raw,0,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),$this->key);
        if ($plain===false) return 'unknown';
        $data=json_decode($plain,true);
        // Serialize the final eligibility check and the provider call with STOP,
        // revoke and entitlement updates. The earlier committed unknown claim
        // survives a crash or rollback of this second transaction.
        return $this->atomic(function() use ($data,$row,$provider,$now) {
            if (!is_array($data) || !$this->allowed($row['installation'],$row['phone_hash'])) {
                $this->query("UPDATE ieb_sms_messages SET state='suppressed',quota_month=NULL WHERE id=? AND state='unknown'",[$row['id']]); return 'suppressed';
            }
            $lead=$data['lead']; $message='Taggart Media Group - New estimate: '.$lead['name'].' | '.$lead['phone'].' | '.$lead['service'];
            try { $result=$provider($data['recipient'],$message); } catch (Throwable $e) { $result='unknown'; }
            if (!in_array($result,['accepted','retryable','unknown'],true)) $result='unknown';
            if ($result==='accepted') $this->query("UPDATE ieb_sms_messages SET state='accepted' WHERE id=? AND state='unknown'",[$row['id']]);
            if ($result==='retryable') {
                if ((int)$row['attempts']+1 >= 3) $this->query("UPDATE ieb_sms_messages SET state='failed',quota_month=NULL WHERE id=? AND state='unknown'",[$row['id']]);
                else $this->query("UPDATE ieb_sms_messages SET state='retry',quota_month=NULL,due_at=? WHERE id=? AND state='unknown'",[$now+60,$row['id']]);
            }
            return $result;
        });
    }
}
