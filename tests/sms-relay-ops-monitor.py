#!/usr/bin/env python3
"""Offline regression for the read-only, aggregate-only relay monitor."""
import json, os, pathlib, sqlite3, subprocess, tempfile, time
root = pathlib.Path(__file__).resolve().parents[1]
script = root / 'sms-relay' / 'ops-monitor.php'
with tempfile.TemporaryDirectory(dir=os.environ.get('TMPDIR')) as tmp:
    tmp = pathlib.Path(tmp)
    db = tmp / 'fixture.sqlite'
    con = sqlite3.connect(db)
    con.executescript('''CREATE TABLE ieb_sms_messages (state TEXT, due_at INTEGER, expires_at INTEGER, ciphertext TEXT, id INTEGER);
    CREATE TABLE ieb_sms_verifications (state TEXT, created_at INTEGER, expires_at INTEGER, ciphertext TEXT);
    CREATE TABLE ieb_sms_delivery (status TEXT, created_at INTEGER, next_poll INTEGER, lookup_failures INTEGER, message_id INTEGER);''')
    now = int(time.time())
    con.execute('INSERT INTO ieb_sms_messages VALUES (?,?,?,?,?)', ('unknown', now-120, now+3600, 'SENSITIVE-LEAD',1))
    con.execute('INSERT INTO ieb_sms_messages VALUES (?,?,?,?,?)', ('queued', now-120, now+3600, 'SENSITIVE-LEAD',2))
    con.execute('INSERT INTO ieb_sms_messages VALUES (?,?,?,?,?)', ('failed', now-120, now+3600, '',3))
    con.execute('INSERT INTO ieb_sms_verifications VALUES (?,?,?,?)', ('queued', now-120, now+300, 'SENSITIVE-CODE'))
    con.execute('INSERT INTO ieb_sms_delivery VALUES (?,?,?,?,?)', ('undelivered',now-1000,now,0,4))
    con.execute('INSERT INTO ieb_sms_delivery VALUES (?,?,?,?,?)', ('sent',now-90000,now-400,2,5))
    con.commit(); con.close()
    cfg = tmp / 'config.json'; cfg.write_text(json.dumps({'database': str(db)}))
    env = dict(os.environ, IEB_RELAY_CONFIG=str(cfg))
    p = subprocess.run(['php', str(script)], env=env, text=True, capture_output=True)
    assert p.returncode == 2, (p.returncode, p.stdout, p.stderr)
    data = json.loads(p.stdout)
    assert data['alerts']['unknown_messages'] == 1
    assert data['alerts']['terminal_failures'] == 1
    assert data['alerts']['overdue_messages'] == 1
    assert data['alerts']['overdue_verifications'] == 1
    assert data['alerts']['delivery_failures'] == 1
    assert data['alerts']['delivery_stale'] == 1
    assert data['alerts']['delivery_lookup_failures'] == 1
    assert data['delivery_states']['undelivered'] == 1 and data['delivery_states']['sent'] == 1
    assert 'SENSITIVE' not in p.stdout + p.stderr
    con=sqlite3.connect(db); con.execute('delete from ieb_sms_messages'); con.execute('delete from ieb_sms_verifications'); con.execute('delete from ieb_sms_delivery'); con.commit(); con.close()
    p = subprocess.run(['php', str(script)], env=env, text=True, capture_output=True)
    assert p.returncode == 0 and json.loads(p.stdout)['healthy'] is True, (p.returncode, p.stdout, p.stderr)
    cfg.write_text(json.dumps({'database': str(tmp/'missing.sqlite')}))
    p = subprocess.run(['php', str(script)], env=env, text=True, capture_output=True)
    assert p.returncode == 1 and not (tmp/'missing.sqlite').exists(), (p.returncode, p.stdout, p.stderr)
print('PASS aggregate-only monitor detects unknown and backlog; clean and missing-db cases fail safely')
