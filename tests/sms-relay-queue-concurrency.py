#!/usr/bin/env python3
"""Multiprocess SQLite cap and crash tests. Fake provider only."""
import os, pathlib, secrets, sqlite3, subprocess, tempfile
root = pathlib.Path(__file__).resolve().parent.parent
worker = str(root / 'tests/sms-relay-worker-fixture.php')
with tempfile.TemporaryDirectory(dir=os.environ.get('TMPDIR')) as tmp:
    env = dict(os.environ, IEB_TEST_DB=str(pathlib.Path(tmp)/'queue.db'), IEB_TEST_KEY=secrets.token_hex(32))
    subprocess.run(['php', worker, 'setup'], env=env, check=True)
    jobs = [subprocess.Popen(['php',worker,'send'], env=env, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True) for _ in range(12)]
    outputs = []
    for job in jobs:
        out, err = job.communicate(timeout=20)
        assert job.returncode == 0, err
        outputs.append(out)
    assert outputs.count('accepted') == 2 and outputs.count('capped') == 10, outputs
    db=sqlite3.connect(env['IEB_TEST_DB'])
    assert db.execute("SELECT count(*) FROM ieb_sms_messages WHERE state='accepted'").fetchone()[0]==2
    db.close()
    env['IEB_TEST_DB']=str(pathlib.Path(tmp)/'crash.db')
    subprocess.run(['php',worker,'setup'], env=env,check=True)
    crashed=subprocess.run(['php',worker,'crash'],env=env)
    assert crashed.returncode==77
    assert subprocess.check_output(['php',worker,'send'],env=env,text=True)=='accepted'
    assert subprocess.check_output(['php',worker,'send'],env=env,text=True)=='capped'
    db=sqlite3.connect(env['IEB_TEST_DB'])
    assert db.execute("SELECT count(*) FROM ieb_sms_messages WHERE state='unknown'").fetchone()[0]==1
    db.close()
print('PASS: 12 concurrent fake dispatches enforce cap; process crash stays unknown and reserves quota')
