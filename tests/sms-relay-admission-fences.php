<?php
// Synthetic SQLite fixtures; no provider network.
require __DIR__.'/../sms-relay/queue.php';
require __DIR__.'/../sms-relay/verification.php';
function fence_check($condition,$message) { if (!$condition) throw new RuntimeException($message); }
class FencePDO extends PDO {
    public $afterCommit=null;
    public function exec(string $sql): int|false {
        $result=parent::exec($sql);
        if ($sql==='COMMIT' && $this->afterCommit) { $fn=$this->afterCommit; $this->afterCommit=null; $fn(); }
        return $result;
    }
}
$key=random_bytes(32); $now=1700000000; $phone='+15550000101';
$db=new FencePDO('sqlite::memory:'); $q=new IebSmsQueue($db,$key); $q->install(); $q->configureInstallation('install_1',100,true);
$v=new IebSmsVerification($db,$key);
for ($i=0;$i<100;$i++) { try { $v->request('install_1',sprintf('%032x',$i+1),$phone,'admin_1','admin-sms-v1',true,$now); } catch (DomainException $e) {} }
fence_check((int)$db->query('SELECT count(*) FROM ieb_sms_verifications')->fetchColumn()===3,'rate limited verification requests must not create audit rows');
$q->consent('install_1',$phone,'admin_1','notice-v1',$now);
$q->enqueue('install_1','event_1',$phone,['name'=>'A','phone'=>'B','service'=>'C'],$now);
$db->afterCommit=fn()=>$q->cleanup($now+3601);
$calls=0; $q->dispatchOne(function() use (&$calls) { ++$calls; return 'accepted'; },$now);
fence_check($calls===0,'maintenance expired in-flight claim must fence provider call');
$db2=new PDO('sqlite::memory:'); $q2=new IebSmsQueue($db2,$key); $q2->install(); $q2->configureInstallation('other',100,true); $v2=new IebSmsVerification($db2,$key);
$stmt=$db2->prepare("INSERT INTO ieb_sms_verifications (installation,request_id,phone_hash,actor,notice,code_hash,ciphertext,state,created_at,expires_at) VALUES (?,?,?,'actor','notice','code','cipher','queued',?,?)");
for ($i=0;$i<200;$i++) $stmt->execute(['installation_'.$i,sprintf('%032x',$i+1),'phone_'.$i,$now,$now+600]);
$v2->request('other',str_repeat('f',32),$phone,'admin_1','admin-sms-v1',true,$now);
fence_check((int)$db2->query('SELECT count(*) FROM ieb_sms_verifications')->fetchColumn()===200,'global verification admission must be bounded');
echo "PASS: admission and in-flight cleanup fences\n";
