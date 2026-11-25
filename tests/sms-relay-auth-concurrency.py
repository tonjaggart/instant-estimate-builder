#!/usr/bin/env python3
"""Concurrent replay test against an actual SQLite file. No network access."""
import concurrent.futures
import os
import pathlib
import subprocess
import tempfile

ROOT = pathlib.Path(__file__).resolve().parent.parent
AUTH = ROOT / 'sms-relay' / 'auth.php'
PHP = r'''require $argv[1];
$db = new PDO('sqlite:' . $argv[2], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10]);
if ($argv[3] === 'init') { ieb_auth_install_schema($db); exit; }
$key = str_repeat('ab', 32); $id = 'installation_123'; $ts = '1800000000';
$nonce = str_repeat('c', 32); $body = '{"event":"sample"}';
$sig = ieb_auth_sign($key, $id, 'POST', '/v1/events', $ts, $nonce, $body);
echo ieb_auth_verify($db, $key, $id, 'POST', '/v1/events', $ts, $nonce, $body, $sig, 1800000000) ? 'accepted' : 'rejected';'''


def run(db, mode):
    result = subprocess.run(['php', '-r', PHP, str(AUTH), str(db), mode], capture_output=True, text=True, check=True)
    return result.stdout


scratch = os.environ.get('TMPDIR', str(pathlib.Path.home() / '.hermes/cache/scratch'))
with tempfile.TemporaryDirectory(prefix='ieb-auth-test-', dir=scratch) as d:
    db = pathlib.Path(d) / 'nonce.sqlite'
    run(db, 'init')
    with concurrent.futures.ThreadPoolExecutor(max_workers=24) as pool:
        results = list(pool.map(lambda _: run(db, 'verify'), range(24)))
    assert results.count('accepted') == 1, results
    assert results.count('rejected') == 23, results
print('PASS: 24 concurrent requests, exactly one accepted; no network')
