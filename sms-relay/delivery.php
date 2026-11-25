<?php
/** Bounded provider status lookup. Never sends or logs provider data. */
final class IebSmsDelivery {
    private $db;
    function __construct(PDO $db) { $this->db=$db; $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION); $db->exec('PRAGMA busy_timeout=5000'); }
    function poll(callable $lookup,int $now): int {
        $s=$this->db->prepare("SELECT message_id,sid FROM ieb_sms_delivery WHERE status NOT IN ('delivered','undelivered','failed','canceled') AND next_poll<=? AND created_at>? ORDER BY next_poll,message_id LIMIT 20");
        $s->execute([$now,$now-86400]); $rows=$s->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            try { $status=$lookup($row['sid']); } catch (Throwable $e) { $status=null; }
            $valid=is_string($status) && in_array($status,['accepted','scheduled','queued','sending','sent','delivered','undelivered','failed','canceled'],true);
            // Conditional update prevents a concurrent poll from regressing terminal status.
            $u=$this->db->prepare("UPDATE ieb_sms_delivery SET status=CASE WHEN ? THEN ? ELSE status END, lookup_failures=CASE WHEN ? THEN 0 ELSE lookup_failures+1 END, next_poll=? WHERE message_id=? AND status NOT IN ('delivered','undelivered','failed','canceled') AND next_poll<=?");
            $u->execute([(int)$valid,$valid?$status:'', (int)$valid,$now+300,$row['message_id'],$now]);
        }
        return count($rows);
    }
}
