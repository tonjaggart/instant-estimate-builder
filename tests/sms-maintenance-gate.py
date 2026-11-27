#!/usr/bin/env python3
"""Disposable process-level maintenance gate tests; no network except loopback."""
import fcntl
import http.client
import json
import os
import shutil
import socket
import subprocess
import tempfile
import time
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ENTRIES = ('worker.php', 'verification-worker.php', 'status-worker.php', 'maintenance.php', 'ops-monitor.php')


def request(port):
    conn = http.client.HTTPConnection('127.0.0.1', port, timeout=5)
    conn.request('GET', '/')
    response = conn.getresponse()
    result = (response.status, response.getheader('Retry-After'), response.read())
    conn.close()
    return result


def main():
    with tempfile.TemporaryDirectory(prefix='relay-gate-') as t:
        root = Path(t)
        code = root / 'code'
        shutil.copytree(ROOT / 'sms-relay', code, ignore=shutil.ignore_patterns('vendor', '__pycache__'))
        (code / 'vendor').mkdir()
        (code / 'vendor/autoload.php').write_text('<?php\n')
        lockpath = root / 'maintenance.lock'
        lockpath.touch(mode=0o600)
        db = root / 'queue.sqlite'
        db.touch()
        config = root / 'config.json'
        config.write_text(json.dumps({'database': str(db), 'encryption_key': 'ab'*32, 'installations': {}, 'live_send_enabled': False, 'verification_send_enabled': False, 'status_poll_enabled': False}))
        env = dict(os.environ, IEB_RELAY_CONFIG=str(config))
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        server = subprocess.Popen(['php', '-S', f'127.0.0.1:{port}', '-t', str(code / 'public')], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, env=env)
        try:
            for _ in range(100):
                try:
                    baseline = request(port)
                    break
                except OSError:
                    time.sleep(.05)
            else:
                raise AssertionError('HTTP server did not start')
            assert baseline[0] == 404, baseline
            with lockpath.open('r+') as exclusive:
                fcntl.flock(exclusive, fcntl.LOCK_EX | fcntl.LOCK_NB)
                status, retry, body = request(port)
                assert (status, retry) == (503, '60'), (status, retry, body)
                for entry in ENTRIES:
                    result = subprocess.run(['php', str(code / entry)], env=env, capture_output=True, text=True, timeout=5)
                    assert result.returncode == 75, (entry, result.returncode, result.stderr)
                    if entry == 'ops-monitor.php':
                        assert json.loads(result.stdout) == {'healthy': False, 'error': 'maintenance'}, result.stdout
                    else:
                        assert 'maintenance' in result.stderr.lower(), (entry, result.stderr)
                plan = root / 'operator.php'
                plan.write_text('<?php echo "OPERATOR_READY\\n"; fgets(STDIN);')
                contender = subprocess.run(['php', str(code / 'maintenance-exclusive.php'), str(plan)], env=env, capture_output=True, timeout=5)
                assert contender.returncode == 75, (contender.returncode, contender.stderr)
            assert request(port)[0] == 404
            operator = subprocess.Popen(['php', str(code / 'maintenance-exclusive.php'), str(plan)], env=env, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
            try:
                assert operator.stdin is not None and operator.stdout is not None and operator.stderr is not None
                assert operator.stdout.readline().strip() == 'OPERATOR_READY', operator.stderr.read()
                assert request(port)[:2] == (503, '60')
                operator.stdin.write('\n'); operator.stdin.flush()
                assert operator.wait(timeout=5) == 0
            finally:
                if operator.poll() is None:
                    operator.kill(); operator.wait()
            assert request(port)[0] == 404
            crashed = subprocess.Popen(['php', str(code / 'maintenance-exclusive.php'), str(plan)], env=env, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
            assert crashed.stdout is not None
            assert crashed.stdout.readline().strip() == 'OPERATOR_READY'
            assert request(port)[:2] == (503, '60')
            crashed.kill(); crashed.wait(timeout=5)
            assert lockpath.is_file() and request(port)[0] == 404
            for entry in ENTRIES[:3]:
                result = subprocess.run(['php', str(code / entry)], env=env, capture_output=True, text=True, timeout=5)
                assert result.returncode == 2, (entry, result.returncode, result.stderr)
            # Exercise the actual worker entry during a synthetic provider call.
            # The queue and SDK are replaced only inside this disposable copy.
            (code / 'vendor/autoload.php').write_text('''<?php
namespace Twilio\\Rest; class Client { function __construct(...$x) {} function setHttpClient($x) {} }
namespace Twilio\\Http; class CurlClient { function __construct(...$x) {} }
''')
            (code / 'twilio.php').write_text('<?php class IebTwilioAdapter { function __construct(...$x) {} }')
            (code / 'queue.php').write_text('''<?php
class IebSmsQueue { function __construct(...$x) {} function dispatchOne(...$x) { echo "PROVIDER_READY\\n"; fgets(STDIN); return 'accepted'; } }
''')
            config.write_text(json.dumps({'database': str(db), 'encryption_key': 'ab'*32, 'live_send_enabled': True, 'twilio': {'account_sid': 'AC'+'a'*32, 'auth_token': 'synthetic', 'sender': 'synthetic'}}))
            worker = subprocess.Popen(['php', str(code / 'worker.php')], env=env, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
            try:
                assert worker.stdout is not None and worker.stdin is not None and worker.stderr is not None
                assert worker.stdout.readline().strip() == 'PROVIDER_READY', worker.stderr.read()
                with lockpath.open('r+') as exclusive:
                    try:
                        fcntl.flock(exclusive, fcntl.LOCK_EX | fcntl.LOCK_NB)
                        raise AssertionError('provider operation not fenced')
                    except BlockingIOError:
                        pass
                    worker.stdin.write('\n'); worker.stdin.flush()
                    assert worker.wait(timeout=5) == 0
                    fcntl.flock(exclusive, fcntl.LOCK_EX | fcntl.LOCK_NB)
                    fenced = subprocess.run(['php', str(code / 'worker.php')], env=env, capture_output=True, timeout=5)
                    assert fenced.returncode == 75, fenced
            finally:
                if worker.poll() is None:
                    worker.kill(); worker.wait()
            # A real shared holder prevents exclusive acquisition until it exits.
            shared = subprocess.Popen(['php', '-r', "require $argv[1]; $lock=ieb_maintenance_shared(); if (!$lock) exit(75); echo \"READY\\n\"; fgets(STDIN);", str(code / 'maintenance-lock.php')], stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
            try:
                assert shared.stdout is not None and shared.stdin is not None and shared.stderr is not None
                assert shared.stdout.readline().strip() == 'READY', shared.stderr.read()
                with lockpath.open('r+') as exclusive:
                    try:
                        fcntl.flock(exclusive, fcntl.LOCK_EX | fcntl.LOCK_NB)
                        raise AssertionError('exclusive acquired before in-flight request drained')
                    except BlockingIOError:
                        pass
                    shared.stdin.write('\n'); shared.stdin.flush()
                    assert shared.wait(timeout=5) == 0
                    fcntl.flock(exclusive, fcntl.LOCK_EX | fcntl.LOCK_NB)
            finally:
                if shared.poll() is None:
                    shared.kill(); shared.wait()
            lockpath.unlink()
            status, retry, _ = request(port)
            assert (status, retry) == (503, '60'), (status, retry)
            for entry in ENTRIES:
                result = subprocess.run(['php', str(code / entry)], env=env, capture_output=True, timeout=5)
                assert result.returncode == 75, (entry, result.returncode)
            lockpath.touch()
            alias = root / 'hardlink'
            os.link(lockpath, alias)
            assert request(port)[:2] == (503, '60')
            alias.unlink()
            assert request(port)[:2] == (503, None)  # fake worker config is not valid HTTP config
            lockpath.unlink()
            lockpath.symlink_to(config)
            status, retry, _ = request(port)
            assert (status, retry) == (503, '60'), (status, retry)
            lockpath.unlink()
            lockpath.touch(mode=0o000)
            if os.geteuid() != 0:
                status, retry, _ = request(port)
                assert (status, retry) == (503, '60'), (status, retry)
                for entry in ENTRIES:
                    result = subprocess.run(['php', str(code / entry)], env=env, capture_output=True, timeout=5)
                    assert result.returncode == 75, (entry, result.returncode)
            print('PASS: API 503, all cron fenced, provider drain, crash recovery, missing/symlink/hardlink/permission lock')
        finally:
            server.terminate()
            server.wait(timeout=5)


if __name__ == '__main__':
    main()
