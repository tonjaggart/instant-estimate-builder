<?php
// Offline schema upgrade and rollback checks.
require_once __DIR__.'/../sms-relay/queue.php';
function migration_check($ok,$why) { if (!$ok) throw new RuntimeException($why); }
function legacy_verifications(PDO $db): void { $db->exec('CREATE TABLE ieb_sms_verifications (installation TEXT NOT NULL,request_id TEXT NOT NULL,phone_hash TEXT NOT NULL,actor TEXT NOT NULL,notice TEXT NOT NULL,code_hash TEXT NOT NULL,ciphertext TEXT NOT NULL,state TEXT NOT NULL,attempts INTEGER NOT NULL DEFAULT 0,created_at INTEGER NOT NULL,expires_at INTEGER NOT NULL,PRIMARY KEY(installation,request_id))'); }
$db=new PDO('sqlite::memory:'); legacy_verifications($db);
$db->exec("INSERT INTO ieb_sms_verifications VALUES ('installation','00000000000000000000000000000001','hash','actor','notice','code','cipher','unknown',0,1700000000,1700000600)");
$db->exec("INSERT INTO ieb_sms_verifications VALUES ('installation','00000000000000000000000000000002','hash','actor','notice','code','cipher','queued',0,1700000000,1700000600)");
$db->exec('CREATE TABLE ieb_sms_messages (id INTEGER PRIMARY KEY AUTOINCREMENT,installation TEXT NOT NULL,event TEXT NOT NULL,phone_hash TEXT NOT NULL,payload_hash TEXT NOT NULL,ciphertext TEXT NOT NULL,state TEXT NOT NULL,attempts INTEGER NOT NULL DEFAULT 0,due_at INTEGER NOT NULL,quota_month TEXT,UNIQUE(installation,event,phone_hash))');
$db->exec("INSERT INTO ieb_sms_messages (installation,event,phone_hash,payload_hash,ciphertext,state,due_at) VALUES ('installation','event','hash','hash','cipher','queued',1700000000)");
$q=new IebSmsQueue($db,random_bytes(32)); $q->install(); $q->install();
migration_check((int)$db->query('SELECT expires_at FROM ieb_sms_messages')->fetchColumn()===0,'legacy alert not fenced');
migration_check((int)$db->query("SELECT COUNT(*) FROM ieb_sms_verification_budget WHERE request_id='00000000000000000000000000000001' AND month=strftime('%Y-%m','now')")->fetchColumn()===1,'legacy unknown not conservatively reserved in current month');
migration_check($db->query("SELECT state FROM ieb_sms_verifications WHERE request_id='00000000000000000000000000000002'")->fetchColumn()==='expired','legacy queued verification must fail closed');
$db2=new PDO('sqlite::memory:'); legacy_verifications($db2);
$db2->exec("INSERT INTO ieb_sms_verifications VALUES ('installation','00000000000000000000000000000001','hash','actor','notice','code','cipher','unknown',0,9999999999999,9999999999999)");
$q2=new IebSmsQueue($db2,random_bytes(32)); $failed=false; try { $q2->install(); } catch (Throwable $e) { $failed=true; }
migration_check($failed,'invalid legacy date did not fail closed');
$cols=array_column($db2->query('PRAGMA table_info(ieb_sms_verifications)')->fetchAll(PDO::FETCH_ASSOC),'name');
migration_check(!in_array('request_fingerprint',$cols,true),'schema upgrade was not rolled back');
migration_check(!(bool)$db2->query("SELECT 1 FROM sqlite_master WHERE name='ieb_sms_verification_budget'")->fetchColumn(),'budget table survived failed migration');
echo "PASS: atomic legacy migration and rollback\n";
