#!/usr/bin/env python3
"""Start the existing private-socket, network-disabled disposable MySQL fixture."""
import os
from pathlib import Path
import subprocess
import time

base = Path('/Users/jack/.hermes/cache/scratch/ieb-sms-isolated-db')
mysql = Path('/Users/jack/.hermes/cache/scratch/mysql-8.4.11-macos15-x86_64/bin/mysqld')
sock = base / 'mysql.sock'
pid = base / 'lead-trash-mysql.pid'
if sock.exists() or pid.exists() or not mysql.is_file():
    raise RuntimeError('Disposable MySQL unavailable or already in use')
env = dict(os.environ, TMPDIR='/Users/jack/.hermes/cache/scratch', IEB_TEST_WP=str(base / 'wp'))
with (base / 'lead-trash-mysql.log').open('w') as log:
    proc = subprocess.Popen([str(mysql), '--no-defaults', '--datadir='+str(base/'data'), '--socket='+str(sock), '--pid-file='+str(pid), '--skip-networking', '--mysqlx=OFF'], stdout=log, stderr=subprocess.STDOUT, env=env)
    try:
        for _ in range(200):
            if proc.poll() is not None:
                raise RuntimeError('MySQL fixture failed')
            if sock.exists():
                ready = subprocess.run(['php','/Users/jack/.hermes/cache/scratch/wp-cli.phar','--path='+env['IEB_TEST_WP'],'--skip-plugins','--skip-themes','option','get','siteurl'], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, env=env)
                if ready.returncode == 0:
                    break
            time.sleep(.1)
        else:
            raise RuntimeError('MySQL fixture not ready')
        subprocess.run(['php', str(Path(__file__).with_name('lead-trash-wp.php'))], env=env, check=True)
        subprocess.run(['php', str(Path(__file__).with_name('lead-trash-wp.php'))], env=dict(env, IEB_TEST_TRASH_DISABLED='1'), check=True)
    finally:
        proc.terminate()
        try:
            proc.wait(timeout=20)
        except subprocess.TimeoutExpired:
            proc.kill(); proc.wait()
