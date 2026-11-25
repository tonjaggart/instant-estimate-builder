#!/usr/bin/env python3
"""No-network checks that the status worker is disabled by default."""
import json, os, pathlib, subprocess, tempfile
root=pathlib.Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(dir=os.environ.get('TMPDIR')) as d:
    cfg=pathlib.Path(d)/'config.json'; cfg.write_text(json.dumps({'status_poll_enabled':False}))
    env=dict(os.environ,IEB_RELAY_CONFIG=str(cfg))
    p=subprocess.run(['php',str(root/'sms-relay/status-worker.php')],env=env,capture_output=True,text=True)
    assert p.returncode==2 and p.stdout=='' and p.stderr=='Status polling disabled\n', (p.returncode,p.stdout,p.stderr)
    cfg.write_text(json.dumps({'status_poll_enabled':True,'database':str(pathlib.Path(d)/'missing')}))
    p=subprocess.run(['php',str(root/'sms-relay/status-worker.php')],env=env,capture_output=True,text=True)
    assert p.returncode==1 and p.stdout=='' and p.stderr=='Status poll unavailable\n', (p.returncode,p.stdout,p.stderr)
print('PASS status worker disabled and invalid config fail closed without network')
