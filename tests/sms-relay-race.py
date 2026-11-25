#!/usr/bin/env python3
"""STOP and send are serialized; this uses only local fake callbacks."""
import os, pathlib, secrets, sqlite3, subprocess, tempfile, time
root=pathlib.Path(__file__).resolve().parent.parent
worker=root/'tests/sms-relay-race-worker.php'
with tempfile.TemporaryDirectory(dir=os.environ.get('TMPDIR')) as tmp:
    env=dict(os.environ, RACE_DIR=tmp, RACE_KEY=secrets.token_hex(32))
    def run(mode): return subprocess.run(['php',str(worker),mode],env=env,check=True,text=True,capture_output=True)
    run('setup')
    send=subprocess.Popen(['php',str(worker),'send'],env=env,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
    for _ in range(200):
        if (pathlib.Path(tmp)/'entered').exists(): break
        time.sleep(.01)
    else: raise AssertionError('fake provider was not entered')
    stop=subprocess.Popen(['php',str(worker),'stop'],env=env,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
    time.sleep(.25)
    assert stop.poll() is None, 'STOP acknowledged before in-flight provider call completed'
    (pathlib.Path(tmp)/'release').touch()
    assert send.communicate(timeout=10)[0]=='accepted' and send.returncode==0
    assert stop.communicate(timeout=10)[0]=='stopped' and stop.returncode==0
    assert run('send').stdout=='suppressed'
print('PASS: STOP acknowledgment serializes with in-flight fake send and suppresses subsequent messages')
