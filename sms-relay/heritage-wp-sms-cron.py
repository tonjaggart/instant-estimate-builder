#!/usr/bin/env python3
"""Run only due Instant Estimate SMS WP-Cron hooks for Heritage staging.

The site's general WP-Cron is intentionally disabled. This bridge does not
run unrelated WordPress events and emits no job arguments or lead details.
"""
import fcntl
import json
import os
import subprocess
import sys

WP = '/usr/local/bin/wp'
WORKDIR = '/home/master/applications/hapmeudzjj/public_html'
LOCK = '/home/master/applications/hapmeudzjj/private_html/ieb-sms/wp-sms-cron.lock'
ALLOWED = frozenset((
    'ieb_sms_dispatch_lead',
    'ieb_sms_recover_pending',
    'ieb_sms_recover_pending_batch',
    'ieb_sms_recover_failed_fanout',
))
DISCOVER = """$due=[]; foreach (_get_cron_array() as $when=>$events) { if ((int)$when>time()) continue; foreach ($events as $hook=>$jobs) { if (in_array($hook, ['ieb_sms_dispatch_lead','ieb_sms_recover_pending','ieb_sms_recover_pending_batch','ieb_sms_recover_failed_fanout'],true)) $due[$hook]=true; } } echo wp_json_encode(array_keys($due));"""


def run_due(wp, workdir, execute):
    result = execute([wp, 'eval', DISCOVER], cwd=workdir, check=True,
                     capture_output=True, text=True, timeout=45)
    names = json.loads(result.stdout)
    if not isinstance(names, list) or any(not isinstance(x, str) for x in names):
        raise ValueError('Invalid due-event result')
    due = list(dict.fromkeys(name for name in names if name in ALLOWED))
    if due:
        execute([wp, 'cron', 'event', 'run', *due, '--due-now', '--quiet'],
                cwd=workdir, check=True, capture_output=True, text=True, timeout=120)


def main():
    fd = os.open(LOCK, os.O_CREAT | os.O_RDWR, 0o600)
    with os.fdopen(fd, 'r+') as lock:
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            return
        run_due(WP, WORKDIR, subprocess.run)


if __name__ == '__main__':
    try:
        main()
    except Exception:
        print('SMS-specific WP-Cron bridge failed', file=sys.stderr)
        sys.exit(1)
