<?php
/** CLI only: aggregate queue health; no recipient, body, secret or SID output. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/maintenance-lock.php';
$maintenanceLock=ieb_maintenance_shared();
if (!$maintenanceLock) { echo "{\"healthy\":false,\"error\":\"maintenance\"}\n"; exit(75); }
ini_set('display_errors','0'); ini_set('log_errors','0');
try {
    $path = getenv('IEB_RELAY_CONFIG');
    if (!is_string($path) || !is_file($path)) throw new RuntimeException();
    $c = json_decode(file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
    $dbpath = $c['database'] ?? null;
    if (!is_string($dbpath) || !is_file($dbpath) || !is_readable($dbpath)) throw new RuntimeException();
    $db = new PDO('sqlite:'.$dbpath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec('PRAGMA query_only=ON');
    $db->exec('PRAGMA busy_timeout=5000');
    $now=time();
    $count = function(string $sql, array $params=[]) use ($db): int {
        $s=$db->prepare($sql); $s->execute($params); return (int)$s->fetchColumn();
    };
    $alerts=[
        'unknown_messages'=>$count("SELECT COUNT(*) FROM ieb_sms_messages WHERE state IN ('unknown','expired_unknown')"),
        'unknown_verifications'=>$count("SELECT COUNT(*) FROM ieb_sms_verifications WHERE state='unknown'"),
        'terminal_failures'=>$count("SELECT COUNT(*) FROM ieb_sms_messages WHERE state IN ('failed','expired','capped')"),
        'overdue_messages'=>$count("SELECT COUNT(*) FROM ieb_sms_messages WHERE state IN ('queued','retry') AND due_at <= ? AND expires_at > ?",[$now-60,$now]),
        'overdue_verifications'=>$count("SELECT COUNT(*) FROM ieb_sms_verifications WHERE state='queued' AND created_at <= ? AND expires_at > ?",[$now-60,$now]),
        'expired_message_backlog'=>$count("SELECT COUNT(*) FROM ieb_sms_messages WHERE state IN ('queued','retry') AND expires_at <= ?",[$now]),
        'expired_verification_backlog'=>$count("SELECT COUNT(*) FROM ieb_sms_verifications WHERE state='queued' AND expires_at <= ?",[$now]),
        'delivery_failures'=>$count("SELECT COUNT(*) FROM ieb_sms_delivery WHERE status IN ('failed','undelivered','canceled')"),
        'delivery_stale'=>$count("SELECT COUNT(*) FROM ieb_sms_delivery WHERE status NOT IN ('delivered','failed','undelivered','canceled') AND created_at <= ?",[$now-86400]),
        'delivery_lookup_failures'=>$count("SELECT COUNT(*) FROM ieb_sms_delivery WHERE status NOT IN ('delivered','failed','undelivered','canceled') AND lookup_failures>0"),
        'delivery_poll_overdue'=>$count("SELECT COUNT(*) FROM ieb_sms_delivery WHERE status NOT IN ('delivered','failed','undelivered','canceled') AND next_poll<=?",[$now-600]),
        'accepted_without_delivery_id'=>$count("SELECT COUNT(*) FROM ieb_sms_messages m LEFT JOIN ieb_sms_delivery d ON d.message_id=m.id WHERE m.state='accepted' AND d.message_id IS NULL"),
    ];
    $states=[];
    foreach (['queued','retry','accepted','unknown','expired_unknown','failed','expired','suppressed','capped'] as $state) {
        $states[$state]=$count('SELECT COUNT(*) FROM ieb_sms_messages WHERE state=?',[$state]);
    }
    $deliveryStates=[];
    foreach (['accepted','scheduled','queued','sending','sent','delivered','undelivered','failed','canceled'] as $state) {
        $deliveryStates[$state]=$count('SELECT COUNT(*) FROM ieb_sms_delivery WHERE status=?',[$state]);
    }
    $healthy=array_sum($alerts)===0;
    echo json_encode(['healthy'=>$healthy,'alerts'=>$alerts,'message_states'=>$states,'delivery_states'=>$deliveryStates],JSON_THROW_ON_ERROR)."\n";
    exit($healthy ? 0 : 2);
} catch (Throwable $e) { fwrite(STDERR,"Monitor unavailable\n"); exit(1); }
