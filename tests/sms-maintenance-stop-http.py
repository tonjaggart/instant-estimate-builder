#!/usr/bin/env python3
"""Signed synthetic STOP remains callable while relay admission is fenced."""
import base64
import fcntl
import hashlib
import hmac
import http.client
import json
import os
from pathlib import Path
import shutil
import socket
import sqlite3
import subprocess
import tempfile
import time
import urllib.parse
from concurrent.futures import ThreadPoolExecutor

ROOT = Path(__file__).resolve().parents[1]


def main():
    with tempfile.TemporaryDirectory(prefix='stop-gate-', dir=os.environ['TMPDIR']) as tmp:
        root = Path(tmp)
        code = root / 'code'
        shutil.copytree(ROOT / 'sms-relay', code)
        (root / 'maintenance.lock').touch(mode=0o600)
        db = root / 'relay.sqlite'
        db.touch()
        config = root / 'config.json'
        token = 'synthetic-token'
        url = 'https://relay.example.test/v1/twilio'
        account = 'AC' + '1' * 32
        sender = '+12025550102'
        phone = '+12025550101'
        config.write_text(json.dumps({'database': str(db), 'encryption_key': 'ab'*32, 'installations': {}, 'twilio': {'webhook_url': url, 'auth_token': token, 'account_sid': account, 'sender': sender}}))
        env = dict(os.environ, IEB_RELAY_CONFIG=str(config))
        subprocess.run(['php', '-r', "require $argv[1]; $d=new PDO('sqlite:'.$argv[2]); $q=new IebSmsQueue($d,hex2bin(str_repeat('ab',32))); $q->install(); $q->configureInstallation('test',100,true); $q->consent('test','+12025550101','admin','notice',time());", str(code / 'queue.php'), str(db)], check=True, env=env)
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        server = subprocess.Popen(['php', '-S', f'127.0.0.1:{port}', '-t', str(code / 'public')], env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        def request(path, body, headers):
            conn = http.client.HTTPConnection('127.0.0.1', port, timeout=8)
            conn.request('POST', path, body, headers)
            response = conn.getresponse()
            result = response.status, response.read()
            conn.close()
            return result
        try:
            for _ in range(100):
                try:
                    request('/v1/test', '{}', {'Content-Type': 'application/json'})
                    break
                except OSError:
                    time.sleep(.05)
            else:
                raise AssertionError('server did not start')
            fields = {'From': phone, 'To': sender, 'Body': 'STOP', 'MessageSid': 'SM'+'2'*32, 'AccountSid': account}
            signed = url + ''.join(k + fields[k] for k in sorted(fields))
            sig = base64.b64encode(hmac.new(token.encode(), signed.encode(), hashlib.sha1).digest()).decode()
            body = urllib.parse.urlencode(fields)
            with (root / 'maintenance.lock').open('r+') as lock:
                fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
                assert request('/v1/test', '{}', {'Content-Type': 'application/json'})[0] == 503
                assert request('/v1/twilio?ignored=1', '{}', {'Content-Type': 'application/json'})[0] == 503
                headers = {'Content-Type': 'application/x-www-form-urlencoded', 'X-Twilio-Signature': 'bad'}
                assert request('/v1/twilio', body, headers)[0] == 403
                headers['X-Twilio-Signature'] = sig
                # Exercise an actual competing SQLite DDL writer, rather than
                # merely testing webhook behavior before or after migration.
                with sqlite3.connect(db) as writer, ThreadPoolExecutor(max_workers=1) as pool:
                    writer.execute('BEGIN IMMEDIATE')
                    writer.execute('CREATE TABLE synthetic_migration (id INTEGER PRIMARY KEY)')
                    future = pool.submit(request, '/v1/twilio', body, headers)
                    time.sleep(.25)
                    assert not future.done(), 'STOP did not wait for migration transaction'
                    writer.commit()
                    result = future.result(timeout=6)
                assert result[0] == 200, result
                with sqlite3.connect(db) as conn:
                    assert conn.execute('SELECT stopped FROM ieb_sms_suppression').fetchone() == (1,)
                    assert conn.execute('SELECT active FROM ieb_sms_consents').fetchone() == (0,)
            print('PASS: signed STOP commits during admission fence; bad signature rejected')
        finally:
            server.terminate()
            server.wait(timeout=5)


if __name__ == '__main__':
    main()
