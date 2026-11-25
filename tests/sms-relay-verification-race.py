#!/usr/bin/env python3
import os,pathlib,secrets,sqlite3,subprocess,tempfile,time
root=pathlib.Path(__file__).resolve().parent.parent; worker=root/'tests/sms-relay-verification-race-worker.php'
with tempfile.TemporaryDirectory(dir=os.environ.get('TMPDIR')) as d:
    env=dict(os.environ,RACE_DIR=d,RACE_KEY=secrets.token_hex(32))
    def run(mode): return subprocess.run(['php',str(worker),mode],env=env,check=True,text=True,capture_output=True)
    run('setup'); sending=subprocess.Popen(['php',str(worker),'send'],env=env,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
    try:
        for _ in range(200):
            if (pathlib.Path(d)/'entered').exists(): break
            time.sleep(.01)
        else: raise AssertionError('fake provider not entered')
        stopping=subprocess.Popen(['php',str(worker),'stop'],env=env,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
        time.sleep(.2); assert stopping.poll() is None, 'STOP acknowledged during in-flight verification'
        (pathlib.Path(d)/'release').touch(); assert sending.communicate(timeout=8)[0]=='accepted'; assert stopping.communicate(timeout=8)[0]=='stopped'
        with sqlite3.connect(pathlib.Path(d)/'db') as db: assert db.execute('SELECT state,ciphertext FROM ieb_sms_verifications').fetchone()==('cancelled','')
        assert run('send').stdout=='empty'
    finally:
        if sending.poll() is None: sending.kill(); sending.wait()
print('PASS: STOP serializes with verification dispatch and cancels challenge')
