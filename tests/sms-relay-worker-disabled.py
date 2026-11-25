#!/usr/bin/env python3
import json, os, pathlib, secrets, subprocess, tempfile
root=pathlib.Path(__file__).resolve().parent.parent
worker=root/'sms-relay/worker.php'
with tempfile.TemporaryDirectory(dir=os.environ.get('TMPDIR')) as d:
    cfg=pathlib.Path(d)/'config.json'; cfg.write_text(json.dumps({'database':str(pathlib.Path(d)/'db'),'encryption_key':secrets.token_hex(32),'live_send_enabled':False}))
    p=subprocess.run(['php',str(worker)],env=dict(os.environ,IEB_RELAY_CONFIG=str(cfg)),capture_output=True,text=True)
    assert p.returncode!=0 and 'disabled' in p.stderr and p.stdout=='', (p.returncode,p.stdout,p.stderr)
    p=subprocess.run(['php',str(root/'sms-relay/verification-worker.php')],env=dict(os.environ,IEB_RELAY_CONFIG=str(cfg)),capture_output=True,text=True)
    assert p.returncode!=0 and 'disabled' in p.stderr and p.stdout=='', (p.returncode,p.stdout,p.stderr)
    cfg.write_text(json.dumps({'live_send_enabled':True}))
    p=subprocess.run(['php',str(root/'sms-relay/verification-worker.php')],env=dict(os.environ,IEB_RELAY_CONFIG=str(cfg)),capture_output=True,text=True)
    assert p.returncode!=0 and 'disabled' in p.stderr and p.stdout=='', (p.returncode,p.stdout,p.stderr)
print('PASS: production worker fails closed when sending is not explicitly enabled')
