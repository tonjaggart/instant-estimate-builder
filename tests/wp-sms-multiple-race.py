#!/usr/bin/env python3
"""Competing real WordPress processes; no network and no real provider."""
import json
import os
from pathlib import Path
import subprocess
import tempfile

worker = str(Path(__file__).with_name('wp-sms-multiple-race-worker.php'))
base = ['php', '-d', 'error_reporting=24575', worker]
root = Path(os.environ.get('IEB_MULTI_TEST_WP') or os.environ['IEB_TEST_WP']).resolve()
assert root.name == 'wp' and root.parent.name in ('ieb-sms-multi-recipient-test', 'ieb-sms-isolated-db')

def run(*args, env):
    return subprocess.check_output(base + list(map(str, args)), env=env, text=True)

def race(inputs, env):
    for f in Path(env['IEB_RACE_DIR']).glob('ready-*'):
        f.unlink()
    processes = [subprocess.Popen(base + args, env=env, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True) for args in inputs]
    for process in processes:
        out, err = process.communicate(timeout=20)
        if process.returncode:
            raise RuntimeError(out + err)

with tempfile.TemporaryDirectory(dir=root.parent) as directory:
    env = {**os.environ, 'IEB_RACE_DIR': directory}
    run('setup', env=env)
    race([['enroll', '+12025550101'], ['enroll', '(202) 555-0101']], env)
    rows = json.loads(run('rows', env=env))
    assert len(rows) == 1, rows
    requests = [json.loads(line) for line in Path(directory, 'requests').read_text().splitlines()]
    assert len(requests) == 2 and len({r['request_id'] for r in requests}) == 1, requests
    race([['enroll', '+12025550102'], ['enroll', '+12025550103']], env)
    rows = json.loads(run('rows', env=env))
    assert len(rows) == 3 and len({r['phone'] for r in rows.values()}) == 3, rows
    lead = run('lead-setup', env=env).strip()
    race([['lead', lead], ['lead', lead]], env)
    jobs = json.loads(run('jobs', lead, env=env))
    assert len(jobs) == 3 and len({j['uid'] for j in jobs}) == 3 and len({j['event'] for j in jobs}) == 3, jobs
    print('PASS: concurrent normalized duplicate and distinct enrollments; concurrent submissions create one job per recipient')
    # Competing fanout may snapshot before removal; its stale job must not dispatch afterward.
    lead2 = run('lead-setup', env=env).strip()
    before = json.loads(run('rows', env=env))
    removed = next(iter(before))
    race([['remove', removed], ['lead', lead2]], env)
    after = json.loads(run('rows', env=env))
    assert removed not in after and all(after[k] == v for k, v in before.items() if k != removed), after
    run('dispatch', lead2, removed, env=env)
    assert not Path(directory, 'events').exists(), 'removed recipient dispatched after removal'
    # A duplicate stale removal is harmless even when re-enrollment races it.
    target = next(iter(after)); phone = after[target]['phone']
    race([['remove', target], ['enroll', phone]], env)
    rows = json.loads(run('rows', env=env))
    assert target not in rows, rows
    assert run('remove-now', target, env=env).strip() == '1'
    assert json.loads(run('rows', env=env)) == rows
    assert all(r['phone'] != phone or r['status'] == 'pending' for r in rows.values()), rows
    print('PASS: concurrent removal with fanout and re-enrollment; stale removal is harmless')
