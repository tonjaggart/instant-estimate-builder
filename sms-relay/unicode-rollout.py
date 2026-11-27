#!/usr/bin/env python3
"""Synthetic-only rollout experiment. Never point at a real service."""
import argparse
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import sqlite3
import stat
import subprocess
import sys
import tempfile
import time

FLAGS = ('live_send_enabled', 'verification_send_enabled')
NEW_TABLES = {'ieb_sms_message_segments', 'ieb_sms_segment_caps'}


def syncdir(path):
    fd = os.open(str(path), os.O_RDONLY)
    try: os.fsync(fd)
    finally: os.close(fd)


def atomic(path, data, mode=0o600):
    fd, temp = tempfile.mkstemp(prefix='.rollout-', dir=str(path.parent))
    try:
        os.fchmod(fd, mode)
        with os.fdopen(fd, 'wb') as out:
            out.write(data); out.flush(); os.fsync(out.fileno())
        os.replace(temp, path); syncdir(path.parent)
    finally:
        if os.path.exists(temp): os.unlink(temp)


def encode(value): return json.dumps(value, sort_keys=True, separators=(',', ':')).encode()
def digest(data): return hashlib.sha256(data).hexdigest()


def regular(path):
    s = path.lstat()
    if not stat.S_ISREG(s.st_mode) or s.st_nlink != 1: raise ValueError('not a single-link regular file: '+str(path))
    return s


def old_hash(database):
    # Capture every row/column of the pre-existing tables. The two additive
    # tables are excluded, never columns from old tables. Sort for stable order.
    with sqlite3.connect('file:'+str(database)+'?mode=ro', uri=True) as db:
        db.execute('BEGIN')
        tables = [r[0] for r in db.execute("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name") if r[0] not in NEW_TABLES]
        result = []
        for table in tables:
            ident = '"'+table.replace('"','""')+'"'
            cols = [r[1] for r in db.execute('PRAGMA table_info('+ident+')')]
            rows = []
            for row in db.execute('SELECT * FROM '+ident):
                rows.append(encode([{'blob': v.hex()} if isinstance(v, bytes) else v for v in row]).decode())
            result.append((table, cols, sorted(rows)))
        return digest(encode(result))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    for name in ('root','source','config','database','installation','cap'):
        parser.add_argument('--'+name, required=True)
    parser.add_argument('--file', action='append', required=True)
    parser.add_argument('--drain-timeout', type=float, default=10)
    parser.add_argument('--test-crash', choices=['journal','gate','flags','drain','migration','deploy-first','deploy','cap','verify','restore','complete'])
    a = parser.parse_args()
    root, source = Path(a.root).resolve(strict=True), Path(a.source).resolve(strict=True)
    config, database = Path(a.config).resolve(strict=True), Path(a.database).resolve(strict=True)
    scratch = Path(os.environ['TMPDIR']).resolve(strict=True)
    if root == scratch or scratch not in root.parents or not (root/'.unicode-rollout-disposable').is_file():
        raise ValueError('refusing non-disposable fixture (root must be beneath TMPDIR with sentinel)')
    if config.parent != root or database.parent != root: raise ValueError('fixture config and database must be direct children of root')
    if not re.fullmatch(r'[A-Za-z0-9_-]{1,64}', a.installation) or not a.cap.isdecimal() or int(a.cap)<1: raise ValueError('invalid cap or installation')
    regular(config); regular(database)
    code = root/'code'
    if not code.is_dir() or code.is_symlink(): raise ValueError('fixture code directory required')
    trusted = Path(__file__).resolve().parent
    # This cannot bootstrap an old uncooperative process. Require the marker-aware
    # gate and webhook bypass already present in the fixture before any change.
    for bootstrap in ('maintenance-lock.php','public/index.php'):
        regular(code/bootstrap)
        if (code/bootstrap).read_bytes() != (trusted/bootstrap).read_bytes():
            raise ValueError('marker-aware bootstrap required before rollout')
    files = sorted(set(a.file))
    if len(files)!=len(a.file): raise ValueError('duplicate file')
    if set(files) & {'maintenance-lock.php', 'public/index.php'}:
        raise ValueError('trusted bootstrap is not replaceable by fixture rollout')
    hashes = {}
    for name in files:
        p=Path(name)
        if p.is_absolute() or '..' in p.parts or str(p)!=name or p.suffix != '.php': raise ValueError('invalid deployment file')
        src, dst = source/p, code/p
        if not src.resolve(strict=True).is_relative_to(source) or not dst.resolve(strict=True).is_relative_to(code): raise ValueError('file escapes directory')
        regular(src); regular(dst)
        hashes[name] = digest(src.read_bytes())
    dependencies = {name:digest((source/name).read_bytes()) for name in ('queue.php','lead-format.php')}
    helper=trusted/'unicode-rollout-migrate.php'
    identity = {'source':str(source), 'root':str(root), 'config':str(config), 'database':str(database), 'files':hashes, 'dependencies':dependencies, 'helper':digest(helper.read_bytes()), 'installation':a.installation, 'cap':int(a.cap)}
    gate, journal = root/'maintenance.active', root/'unicode-rollout-journal.json'
    operator = root/'unicode-rollout.lock'
    opfd=os.open(str(operator), os.O_CREAT|os.O_RDWR|os.O_NOFOLLOW, 0o600)
    try:
        opstat=regular(operator); opened=os.fstat(opfd)
        if (opstat.st_dev,opstat.st_ino)!=(opened.st_dev,opened.st_ino) or opstat.st_uid!=os.geteuid() or stat.S_IMODE(opstat.st_mode)&0o077:
            raise ValueError('operator lock must be private and owned by this user')
        fcntl.flock(opfd,fcntl.LOCK_EX|fcntl.LOCK_NB)
        lockpath=root/'maintenance.lock'; regular(lockpath)
        lockfd=os.open(str(lockpath),os.O_RDONLY|os.O_NOFOLLOW)
        try:
            st=os.fstat(lockfd); cur=regular(lockpath)
            if (st.st_dev,st.st_ino)!=(cur.st_dev,cur.st_ino): raise ValueError('gate lock changed')
            if journal.exists():
                j=json.loads(journal.read_text())
                if j['identity']!=identity: raise ValueError('journal generation mismatch; keep gate closed')
                if j.get('complete') and not gate.exists():
                    journal.unlink(); syncdir(root); j=None
            else: j=None
            if j is None:
                if gate.exists(): raise ValueError('unowned maintenance marker')
                c=json.loads(config.read_text())
                if c.get('database')!=str(database): raise ValueError('config database mismatch')
                for key in FLAGS:
                    if key in c and type(c[key]) is not bool: raise ValueError('send flags must be boolean')
                flags={key: {'present':key in c,'value':c.get(key)} for key in FLAGS}
                rest={k:v for k,v in c.items() if k not in FLAGS}
                j={'identity':identity,'flags':flags,'config_rest_hash':digest(encode(rest)), 'old_hash':None,'complete':False}
                atomic(journal,encode(j)); crash(a,'journal')
            def config_flags(disabled):
                c=json.loads(config.read_text())
                rest={k:v for k,v in c.items() if k not in FLAGS}
                if digest(encode(rest))!=j['config_rest_hash']: raise ValueError('config generation changed')
                for key in FLAGS:
                    if disabled: c[key]=False
                    elif j['flags'][key]['present']: c[key]=j['flags'][key]['value']
                    else: c.pop(key,None)
                atomic(config,encode(c),stat.S_IMODE(regular(config).st_mode))
            atomic(gate,b'fixture rollout in progress\n'); crash(a,'gate')
            config_flags(True); crash(a,'flags')
            deadline=time.monotonic()+a.drain_timeout
            while True:
                try: fcntl.flock(lockfd,fcntl.LOCK_EX|fcntl.LOCK_NB); break
                except BlockingIOError:
                    if time.monotonic()>=deadline: raise TimeoutError('workers failed to drain')
                    time.sleep(.05)
            crash(a,'drain')
            if j['old_hash'] is None:
                j['old_hash']=old_hash(database); atomic(journal,encode(j))
            helper=Path(__file__).resolve().with_name('unicode-rollout-migrate.php')
            def migrate(action):
                if any(digest((source/name).read_bytes())!=expected for name,expected in dependencies.items()) or digest(helper.read_bytes())!=identity['helper']:
                    raise ValueError('migration source generation changed')
                # Lock fd is inherited: if this parent is killed, the child cannot
                # continue mutating after its exclusive flock has been released.
                subprocess.run(['php',str(helper),str(source),str(database),str(config),action,a.installation,a.cap],check=True,pass_fds=(lockfd,opfd))
            migrate('migrate'); crash(a,'migration')
            for index,name in enumerate(files):
                src,dst=source/name,code/name
                data=src.read_bytes()
                if digest(data)!=hashes[name]: raise ValueError('source generation changed')
                atomic(dst,data,stat.S_IMODE(regular(dst).st_mode))
                if index == 0: crash(a,'deploy-first')
            crash(a,'deploy')
            migrate('cap'); crash(a,'cap')
            if old_hash(database)!=j['old_hash']: raise ValueError('legacy record hash changed; gate retained')
            for name in files:
                if digest((code/name).read_bytes())!=hashes[name]: raise ValueError('deployed file mismatch')
            crash(a,'verify')
            config_flags(False); crash(a,'restore')
            j['complete']=True; atomic(journal,encode(j)); crash(a,'complete')
            gate.unlink(); syncdir(root)
            print('Synthetic rollout complete; legacy record hash verified')
        finally: os.close(lockfd)
    finally: os.close(opfd)


def crash(args, phase):
    if args.test_crash==phase: os._exit(86)


if __name__=='__main__':
    try: main()
    except Exception as exc:
        print('rollout failed; if marker exists, admission remains fenced: '+str(exc),file=sys.stderr)
        sys.exit(1)
