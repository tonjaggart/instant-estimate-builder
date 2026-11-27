<?php
// Disposable SQLite and synthetic provider only; never loads staging configuration.
require __DIR__.'/../sms-relay/queue.php';
function safety_check($ok,$message) { if (!$ok) throw new RuntimeException($message); }
$db=new PDO('sqlite::memory:'); $q=new IebSmsQueue($db,random_bytes(32)); $q->install();
$db->exec('DROP TRIGGER ieb_sms_segment_claim_guard'); $db->exec('DROP TABLE ieb_sms_message_segments'); $db->exec('DROP TABLE ieb_sms_segment_caps');
$now=time(); $phone='+12025550101'; $q->configureInstallation('pilot',100,true); $q->consent('pilot',$phone,'admin','notice',$now);
$db->exec("INSERT INTO ieb_sms_messages (installation,event,phone_hash,payload_hash,ciphertext,state,due_at,quota_month,expires_at) VALUES ('pilot','migration_existing','hash','hash','old-cipher','accepted',1700000000,'2020-01',1700003600)");
$before=[]; foreach($db->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $table) $before[$table]=$db->query('SELECT * FROM "'.$table.'" ORDER BY rowid')->fetchAll(PDO::FETCH_ASSOC);
$q->migrateSegmentSchema(); $q->migrateSegmentSchema();
foreach($before as $table=>$rows) safety_check($rows===$db->query('SELECT * FROM "'.$table.'" ORDER BY rowid')->fetchAll(PDO::FETCH_ASSOC),'migration changed existing rows: '.$table);
$lead=['name'=>'雪','phone'=>'(202) 555-0192','service'=>'Gutter Replacement','low'=>'$960','high'=>'$2,400','zip'=>'61264'];
$q->enqueue('pilot','one',$phone,$lead,$now); $sent=0;
$oldWorkerBlocked=false; try { $db->exec("UPDATE ieb_sms_messages SET state='unknown' WHERE event='one'"); } catch (PDOException $e) { $oldWorkerBlocked=true; }
safety_check($oldWorkerBlocked && $db->query("SELECT state FROM ieb_sms_messages WHERE event='one'")->fetchColumn()==='queued','mixed old worker must not claim without a reservation');
$result=$q->dispatchOne(function()use(&$sent){$sent++;return 'accepted';},$now);
safety_check($result==='capped' && $sent===0,'without an explicit segment cap a two-segment message must fail closed');
$gsm=$lead; $gsm['name']='A'; $length=strlen(ieb_sms_pilot_six_line($gsm)); $gsm['name'].=str_repeat('A',151-$length);
safety_check(strlen(ieb_sms_pilot_six_line($gsm))===151,'151-septet synthetic lead');
$q->enqueue('pilot','uscellular',$phone,$gsm,$now);
safety_check($q->dispatchOne(function()use(&$sent){$sent++;return 'accepted';},$now)==='capped' && $sent===0,'reserve carrier worst case before provider');
// US Cellular may add page markers and permit only 64 Unicode units per part.
safety_check(ieb_sms_bounded_segments(str_repeat('雪',129))===3,'carrier-specific 64-unit boundary must not undercount');
safety_check(ieb_sms_bounded_segments(str_repeat('a',285))===3,'carrier-specific 142-septet boundary must not undercount');
safety_check(ieb_sms_bounded_segments(str_repeat('a',151))===2,'US Cellular can segment GSM above 150');
$oversized=$lead; $oversized['name']='雪'.str_repeat('x',129-mb_strlen(ieb_sms_pilot_six_line($lead),'UTF-8'));
safety_check(ieb_sms_pilot_six_line($oversized)===false,'reject text requiring more than two carrier segments');
$q->configureInstallation('legacy',100,true); $q->configureSegmentCap('legacy',2); $q->consent('legacy',$phone,'admin','notice',$now);
$ins=$db->prepare("INSERT INTO ieb_sms_messages (installation,event,phone_hash,payload_hash,ciphertext,state,due_at,quota_month,expires_at) VALUES ('legacy','old','hash','hash','','accepted',?,?,?)");
$ins->execute([$now,gmdate('Y-m',$now),$now+3600]);
$short=$lead; $short['name']='A'; $q->enqueue('legacy','new',$phone,$short,$now);
safety_check($q->dispatchOne(function()use(&$sent){$sent++;return 'accepted';},$now)==='capped','unmeasured historical send conservatively consumes two segments');
$q->configureInstallation('uncertain',100,true); $q->configureSegmentCap('uncertain',2); $q->consent('uncertain',$phone,'admin','notice',$now);
$q->enqueue('uncertain','unknown1',$phone,$lead,$now); $q->enqueue('uncertain','unknown2',$phone,$lead,$now);
safety_check($q->dispatchOne(fn()=> 'unknown',$now)==='unknown','unknown provider outcome');
safety_check($q->dispatchOne(function()use(&$sent){$sent++;return 'accepted';},$now)==='capped','unknown claim must retain both reserved segments');
safety_check((int)$db->query("SELECT SUM(s.segments) FROM ieb_sms_message_segments s JOIN ieb_sms_messages m ON m.id=s.message_id WHERE m.installation='uncertain' AND m.state='unknown'")->fetchColumn()===2,'unknown reservation retained');
$q->configureInstallation('retrytest',100,true); $q->configureSegmentCap('retrytest',10); $q->consent('retrytest',$phone,'admin','notice',$now); $q->enqueue('retrytest','retryone',$phone,$lead,$now);
safety_check($q->dispatchOne(fn()=> 'retryable',$now)==='retryable','fixture proves non-acceptance before retry');
$blocked=false; try { $db->exec("UPDATE ieb_sms_messages SET state='unknown' WHERE event='retryone'"); } catch (PDOException $e) { $blocked=true; }
safety_check($blocked,'old worker cannot reuse a stale retry reservation');
$bad=new PDO('sqlite::memory:'); $badq=new IebSmsQueue($bad,random_bytes(32)); $badq->install(); $bad->exec('DROP TRIGGER ieb_sms_segment_claim_guard'); $bad->exec('DROP TABLE ieb_sms_message_segments'); $bad->exec('DROP TABLE ieb_sms_segment_caps'); $bad->exec('CREATE TABLE ieb_sms_segment_caps (wrong TEXT)');
try { $badq->migrateSegmentSchema(); throw new RuntimeException('incompatible schema accepted'); } catch (RuntimeException $e) { if ($e->getMessage()!=='Incompatible segment schema') throw $e; }
safety_check(!(bool)$bad->query("SELECT 1 FROM sqlite_master WHERE name='ieb_sms_message_segments'")->fetchColumn(),'failed migration must roll back all additions');
echo "PASS: additive idempotent migration, explicit cap, conservative carrier accounting\n";
