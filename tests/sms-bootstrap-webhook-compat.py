#!/usr/bin/env python3
"""Check old and new signed STOP/HELP handlers against additive segment migration.

All processes use disposable SQLite, synthetic credentials and no network.
"""
import os
import pathlib
import shutil
import subprocess
import tempfile

ROOT = pathlib.Path(__file__).resolve().parents[1]
OLD = '8e17d2e'
PHP = r'''<?php
require $argv[1].'/queue.php';
require $argv[2].'/sms-relay/vendor/autoload.php';
require $argv[2].'/sms-relay/webhook.php';
$db=new PDO('sqlite:'.$argv[3]); $q=new IebSmsQueue($db,str_repeat('k',32));
$phone='+1'.'202'.'555'.'0101'; $to='+1'.'202'.'555'.'0102';
if ($argv[4]==='setup') { $q->install(); $q->configureInstallation('synthetic',100,true); $q->consent('synthetic',$phone,'admin','notice',time()); exit; }
if ($argv[4]==='migrate') { $q->migrateSegmentSchema(); $q->configureSegmentCap('synthetic',200); exit; }
$url='https://relay.example.test/v1/twilio'; $token='fake-token'; $sid='AC'.str_repeat('2',32);
$hook=new IebTwilioWebhook($q,$url,$token,$sid,$to);
$body=['From'=>$phone,'To'=>$to,'Body'=>$argv[4],'MessageSid'=>'SM'.str_repeat($argv[5],32),'AccountSid'=>$sid];
$sig=(new Twilio\Security\RequestValidator($token))->computeSignature($url,$body);
[$status,$response]=$hook->handle($body,$sig);
if ($status!==200) throw new RuntimeException('signed webhook rejected');
if ($argv[4]==='STOP' && ((int)$db->query("SELECT count(*) FROM ieb_sms_suppression WHERE stopped=1")->fetchColumn()!==1 || (int)$db->query("SELECT active FROM ieb_sms_consents")->fetchColumn()!==0)) throw new RuntimeException('suppression not stored');
if ($argv[4]==='HELP' && strpos($response,'support@taggartmediagroup.com')===false) throw new RuntimeException('HELP changed');
'''

def main():
    with tempfile.TemporaryDirectory(prefix='sms-bootstrap-', dir=os.environ['TMPDIR']) as t:
        temp = pathlib.Path(t)
        old = temp / 'old'
        old.mkdir()
        for name in ('queue.php', 'lead-format.php'):
            (old / name).write_bytes(subprocess.check_output(['git', 'show', f'{OLD}:sms-relay/{name}'], cwd=ROOT))
        runner = temp / 'run.php'
        runner.write_text(PHP)
        db = temp / 'test.sqlite'
        def run(code, operation, digit='1'):
            subprocess.run(['php', str(runner), str(code), str(ROOT), str(db), operation, digit], check=True, timeout=15)
        run(old, 'setup')
        run(old, 'HELP')
        run(ROOT / 'sms-relay', 'migrate')
        run(old, 'STOP', '1')
        run(ROOT / 'sms-relay', 'STOP', '2')
        run(ROOT / 'sms-relay', 'HELP')
        run(ROOT / 'sms-relay', 'migrate')
    print('PASS: old and new signed STOP/HELP handlers work against migrated SQLite; repeated migration')

if __name__ == '__main__':
    main()
