<?php
// Synthetic offline fixture; no provider/network.
require_once __DIR__.'/../sms-relay/queue.php'; require_once __DIR__.'/../sms-relay/verification.php';
function retention_check($ok,$why) { if (!$ok) throw new RuntimeException($why); }
$db=new PDO('sqlite::memory:'); $key=random_bytes(32); $q=new IebSmsQueue($db,$key); $q->install(); $v=new IebSmsVerification($db,$key); $now=1700000000;
$q->configureInstallation('install_1',100,true); $phone='+15550000101'; $id=str_repeat('a',32);
$v->request('install_1',$id,$phone,'admin_1','admin-sms-v1',true,$now);
$v->dispatch(fn()=>'unknown',$now);
$q->cleanup($now+3601);
$row=$db->query('SELECT * FROM ieb_sms_verifications')->fetch(PDO::FETCH_ASSOC);
retention_check($row['phone_hash']==='' && $row['actor']==='' && $row['notice']==='' && $row['code_hash']==='' && $row['ciphertext']==='', 'verification metadata not erased');
$v->request('install_1',$id,$phone,'admin_1','admin-sms-v1',true,$now+3601);
retention_check($v->dispatch(fn()=>'accepted',$now+3601)==='empty','uncertain verification replayed');
try { $v->request('install_1',$id,$phone,'other','admin-sms-v1',true,$now+3601); throw new RuntimeException('conflicting replay accepted'); } catch (DomainException $e) {}
$q->consent('install_1',$phone,'admin_1','notice-v1',$now); $lead=['name'=>'A','phone'=>'B','service'=>'C'];
$q->enqueue('install_1','old_event',$phone,$lead,$now); $db->exec("UPDATE ieb_sms_messages SET state='accepted'");
$q->cleanup($now+3601);
retention_check($db->query('SELECT ciphertext FROM ieb_sms_messages')->fetchColumn()==='', 'legacy terminal ciphertext retained');
retention_check($q->enqueue('install_1','old_event',$phone,$lead,$now+3601)==='duplicate','event idempotency lost');
$db2=new PDO('sqlite::memory:'); $q2=new IebSmsQueue($db2,$key); $q2->install(); $v2=new IebSmsVerification($db2,$key);
$insert=$db2->prepare("INSERT INTO ieb_sms_verifications (installation,request_id,phone_hash,actor,notice,code_hash,ciphertext,state,created_at,expires_at) VALUES ('install_1',?,'hash','actor','notice','code','cipher','queued',?,?)");
for ($i=0;$i<200;$i++) $insert->execute([sprintf('%032x',$i+1),$now,$now+600]);
$v2->dispatch(fn()=>throw new RuntimeException('must not send'),$now+601);
retention_check((int)$db2->query("SELECT COUNT(*) FROM ieb_sms_verifications WHERE state='expired'")->fetchColumn()<=100,'verification expiry sweep was unbounded');
class RetentionInterleavePDO extends PDO {
    public $afterCommit=null;
    public function exec(string $statement): int|false {
        $result=parent::exec($statement);
        if ($statement==='COMMIT' && $this->afterCommit) { $fn=$this->afterCommit; $this->afterCommit=null; $fn(); }
        return $result;
    }
}
$db3=new RetentionInterleavePDO('sqlite::memory:'); $q3=new IebSmsQueue($db3,$key); $q3->install(); $v3=new IebSmsVerification($db3,$key); $q3->configureInstallation('install_1',100,true);
$v3->request('install_1',str_repeat('b',32),$phone,'admin_1','admin-sms-v1',true,$now);
$db3->afterCommit=fn()=>$q3->cleanup($now+3601);
$calls=0; $v3->dispatch(function() use (&$calls) { ++$calls; return 'accepted'; },$now);
retention_check($calls===0,'cleanup did not fence an expired in-flight verification claim');
echo "PASS: bounded cleanup preserves idempotency and unknown fencing\n";
