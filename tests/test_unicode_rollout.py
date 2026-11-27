#!/usr/bin/env python3
"""End-to-end tests operate only under disposable TMPDIR fixtures."""
import base64
import hashlib
import hmac
import urllib.parse
import fcntl
import http.client
import socket
import time
import json
import os
from pathlib import Path
import shutil
import sqlite3
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
INSTALLER = ROOT / 'sms-relay' / 'unicode-rollout.py'

class Rollout(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(prefix='rollout-', dir=os.environ['TMPDIR'])
        self.root = Path(self.tmp.name).resolve()
        (self.root / '.unicode-rollout-disposable').touch()
        self.code = self.root / 'code'
        shutil.copytree(ROOT / 'sms-relay', self.code)
        (self.root / 'maintenance.lock').touch(mode=0o600)
        self.db = self.root / 'queue.sqlite'
        self.config = self.root / 'config.json'
        self.original = {'database': str(self.db), 'encryption_key': 'ab'*32, 'installations': {}, 'live_send_enabled': True, 'verification_send_enabled': False, 'unrelated': {'x': 42}}
        self.config.write_text(json.dumps(self.original))
        subprocess.run(['php', '-r', "require $argv[1]; $q=new IebSmsQueue(new PDO('sqlite:'.$argv[2]),hex2bin(str_repeat('ab',32))); $q->install(); $q->configureInstallation('synthetic',100,true);", str(self.code/'queue.php'), str(self.db)], check=True)
        with sqlite3.connect(self.db) as conn:
            conn.execute('DROP TRIGGER ieb_sms_segment_claim_guard')
            conn.execute('DROP TABLE ieb_sms_message_segments')
            conn.execute('DROP TABLE ieb_sms_segment_caps')
            conn.execute("INSERT INTO ieb_sms_messages (installation,event,phone_hash,payload_hash,ciphertext,state,due_at,quota_month,expires_at) VALUES ('synthetic','old','h','p','cipher','accepted',1,'2020-01',2)")
        self.args = [str(INSTALLER), '--root', str(self.root), '--source', str(ROOT/'sms-relay'), '--config', str(self.config), '--database', str(self.db), '--installation', 'synthetic', '--cap', '200', '--file', 'queue.php', '--file', 'lead-format.php']

    def tearDown(self): self.tmp.cleanup()
    def run_rollout(self, *extra, env=None):
        return subprocess.run(self.args + list(extra), text=True, capture_output=True, env=env or os.environ.copy(), timeout=30)

    def test_success_and_idempotent_rerun_preserves_config_and_records(self):
        before = sqlite3.connect(self.db).execute('select * from ieb_sms_messages').fetchall()
        for _ in range(2):
            result = self.run_rollout()
            self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(json.loads(self.config.read_text()), self.original)
        with sqlite3.connect(self.db) as db:
            self.assertEqual(db.execute('select * from ieb_sms_messages').fetchall(), before)
            self.assertEqual(db.execute('select monthly_cap from ieb_sms_segment_caps where installation=?', ('synthetic',)).fetchone(), (200,))
        self.assertFalse((self.root/'maintenance.active').exists())

    def test_crash_each_phase_and_forward_recovery(self):
        for phase in ('journal','gate','flags','drain','migration','deploy-first','deploy','cap','verify','restore','complete'):
            with self.subTest(phase=phase):
                crashed = self.run_rollout('--test-crash', phase)
                self.assertEqual(crashed.returncode, 86, crashed.stderr)
                if phase not in ('journal',):
                    self.assertTrue((self.root/'maintenance.active').exists(), phase)
                resumed = self.run_rollout()
                self.assertEqual(resumed.returncode, 0, resumed.stderr)
                self.assertEqual(json.loads(self.config.read_text()), self.original)
                self.assertFalse((self.root/'maintenance.active').exists())

    def test_failed_migration_stays_fenced_then_recovers(self):
        with sqlite3.connect(self.db) as db:
            db.execute('CREATE TABLE ieb_sms_segment_caps (broken TEXT)')
        result=self.run_rollout()
        self.assertNotEqual(result.returncode,0)
        self.assertTrue((self.root/'maintenance.active').exists())
        self.assertFalse(json.loads(self.config.read_text())['live_send_enabled'])
        with sqlite3.connect(self.db) as db: db.execute('DROP TABLE ieb_sms_segment_caps')
        result=self.run_rollout()
        self.assertEqual(result.returncode,0,result.stderr)
        self.assertEqual(json.loads(self.config.read_text()),self.original)

    def test_timeout_preserves_gate_until_drain_and_retry(self):
        with (self.root/'maintenance.lock').open('r') as worker:
            fcntl.flock(worker,fcntl.LOCK_SH|fcntl.LOCK_NB)
            result=self.run_rollout('--drain-timeout','0.1')
            self.assertNotEqual(result.returncode,0)
            self.assertIn('failed to drain',result.stderr)
            self.assertTrue((self.root/'maintenance.active').exists())
            self.assertFalse(json.loads(self.config.read_text())['live_send_enabled'])
        result=self.run_rollout()
        self.assertEqual(result.returncode,0,result.stderr)

    def test_private_operator_lock_required(self):
        lock=self.root/'unicode-rollout.lock'; lock.touch(mode=0o666); lock.chmod(0o666)
        failed=self.run_rollout()
        self.assertNotEqual(failed.returncode,0)
        self.assertFalse((self.root/'maintenance.active').exists())

    def test_missing_send_flag_remains_missing(self):
        del self.original['verification_send_enabled']
        self.config.write_text(json.dumps(self.original))
        result=self.run_rollout()
        self.assertEqual(result.returncode,0,result.stderr)
        self.assertEqual(json.loads(self.config.read_text()),self.original)

    def test_unbootstrapped_gate_is_rejected_before_any_changes(self):
        (self.code/'maintenance-lock.php').write_text('<?php // older, non-persistent gate')
        failed=self.run_rollout()
        self.assertNotEqual(failed.returncode,0,failed.stderr)
        self.assertFalse((self.root/'maintenance.active').exists())
        self.assertFalse((self.root/'unicode-rollout-journal.json').exists())
        self.assertEqual(json.loads(self.config.read_text()),self.original)

    def assert_bootstrap_replacement_rejected(self, bootstrap):
        source = self.root / 'candidate'
        shutil.copytree(ROOT / 'sms-relay', source)
        (source / bootstrap).write_text('<?php // no admission fence')
        args = self.args.copy()
        args[args.index('--source') + 1] = str(source)
        args.extend(['--file', bootstrap])
        failed = subprocess.run(args, capture_output=True, text=True, timeout=30)
        self.assertNotEqual(failed.returncode, 0, failed.stderr)
        self.assertFalse((self.root / 'maintenance.active').exists())
        self.assertFalse((self.root / 'unicode-rollout-journal.json').exists())
        self.assertEqual(json.loads(self.config.read_text()), self.original)
        self.assertEqual((self.code / bootstrap).read_bytes(),
                         (ROOT / 'sms-relay' / bootstrap).read_bytes())

    def test_rejects_replacing_trusted_lock(self):
        self.assert_bootstrap_replacement_rejected('maintenance-lock.php')

    def test_rejects_replacing_trusted_front_controller(self):
        self.assert_bootstrap_replacement_rejected('public/index.php')

    def test_changed_legacy_record_fails_closed(self):
        self.assertEqual(self.run_rollout('--test-crash','migration').returncode,86)
        with sqlite3.connect(self.db) as db:
            db.execute("UPDATE ieb_sms_messages SET ciphertext='changed' WHERE event='old'")
        result=self.run_rollout()
        self.assertNotEqual(result.returncode,0)
        self.assertIn('legacy record hash changed',result.stderr)
        self.assertTrue((self.root/'maintenance.active').exists())
        self.assertFalse(json.loads(self.config.read_text())['live_send_enabled'])

    def test_generation_mismatch_stays_fenced(self):
        self.assertEqual(self.run_rollout('--test-crash','migration').returncode, 86)
        args = self.args.copy(); args[args.index('--cap')+1] = '201'
        failed = subprocess.run(args, capture_output=True, text=True)
        self.assertNotEqual(failed.returncode, 0)
        self.assertIn('generation mismatch', failed.stderr)
        self.assertTrue((self.root/'maintenance.active').exists())
        self.assertFalse(json.loads(self.config.read_text())['live_send_enabled'])
        self.assertEqual(self.run_rollout().returncode, 0)

    def test_contention_and_live_holder_drains_with_api_503(self):
        with (self.root/'unicode-rollout.lock').open('w') as operator:
            os.fchmod(operator.fileno(),0o600)
            fcntl.flock(operator, fcntl.LOCK_EX|fcntl.LOCK_NB)
            result=self.run_rollout()
            self.assertNotEqual(result.returncode, 0)
            self.assertIn('Resource temporarily unavailable',result.stderr)
            self.assertFalse((self.root/'maintenance.active').exists())
        with socket.socket() as sock:
            sock.bind(('127.0.0.1',0)); port=sock.getsockname()[1]
        token='synthetic-token'; url='https://relay.example.test/v1/twilio'; account='AC'+'1'*32
        phone='+12025550101'; sender='+12025550102'
        self.original['twilio']={'webhook_url':url,'auth_token':token,'account_sid':account,'sender':sender}
        self.config.write_text(json.dumps(self.original))
        subprocess.run(['php','-r',"require $argv[1]; $q=new IebSmsQueue(new PDO('sqlite:'.$argv[2]),hex2bin(str_repeat('ab',32))); $q->consent('synthetic','+12025550101','admin','notice',time());",str(self.code/'queue.php'),str(self.db)],check=True)
        env=dict(os.environ, IEB_RELAY_CONFIG=str(self.config))
        server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(self.code/'public')],env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        def req():
            conn=http.client.HTTPConnection('127.0.0.1',port,timeout=3)
            conn.request('GET','/'); response=conn.getresponse()
            result=(response.status,response.getheader('Retry-After')); response.read(); conn.close(); return result
        try:
            for _ in range(100):
                try: req(); break
                except OSError: time.sleep(.05)
            with (self.root/'maintenance.lock').open('r') as worker:
                fcntl.flock(worker,fcntl.LOCK_SH|fcntl.LOCK_NB)
                proc=subprocess.Popen(self.args,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
                try:
                    for _ in range(100):
                        if (self.root/'maintenance.active').exists() and not json.loads(self.config.read_text())['live_send_enabled']: break
                        time.sleep(.05)
                    else: self.fail('installer did not gate and disable')
                    self.assertIsNone(proc.poll())
                    self.assertEqual(req(),(503,'60'))
                    fields={'From':phone,'To':sender,'Body':'STOP','MessageSid':'SM'+'2'*32,'AccountSid':account}
                    signed=url+''.join(k+fields[k] for k in sorted(fields))
                    sig=base64.b64encode(hmac.new(token.encode(),signed.encode(),hashlib.sha1).digest()).decode()
                    conn=http.client.HTTPConnection('127.0.0.1',port,timeout=5)
                    conn.request('POST','/v1/twilio',urllib.parse.urlencode(fields),{'Content-Type':'application/x-www-form-urlencoded','X-Twilio-Signature':sig})
                    response=conn.getresponse(); self.assertEqual(response.status,200); response.read(); conn.close()
                    with sqlite3.connect(self.db) as db:
                        self.assertEqual(db.execute('select stopped from ieb_sms_suppression').fetchone(),(1,))
                    fcntl.flock(worker,fcntl.LOCK_UN)
                    stdout,stderr=proc.communicate(timeout=15)
                    self.assertEqual(proc.returncode,0,stderr)
                    self.assertEqual(req()[0],404)
                finally:
                    if proc.poll() is None: proc.kill(); proc.wait()
        finally: server.terminate(); server.wait(timeout=5)

if __name__ == '__main__': unittest.main()
