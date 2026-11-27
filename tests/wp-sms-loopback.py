#!/usr/bin/env python3
"""Loopback-only integration: WP stub harness -> real relay HTTP -> fake provider."""
import json, os, pathlib, secrets, socket, subprocess, tempfile, time
root=pathlib.Path(__file__).resolve().parent.parent
with tempfile.TemporaryDirectory(dir=os.environ.get('TMPDIR')) as directory:
    directory=pathlib.Path(directory)
    key=secrets.token_hex(32); crypt=secrets.token_hex(32)
    config=directory/'config.json'; database=directory/'relay.sqlite'
    config.write_text(json.dumps({'database':str(database),'encryption_key':crypt,'installations':{'test_install':key},'live_send_enabled':False}))
    env=dict(os.environ,IEB_RELAY_CONFIG=str(config),IEB_TEST_DB=str(database),IEB_TEST_KEY=key,IEB_TEST_CRYPT=crypt)
    init='require "sms-relay/auth.php";require "sms-relay/queue.php"; $c=json_decode(file_get_contents(getenv("IEB_RELAY_CONFIG")),true);$d=new PDO("sqlite:".$c["database"]);$q=new IebSmsQueue($d,hex2bin($c["encryption_key"]));$q->install();ieb_auth_install_schema($d);$q->configureInstallation("test_install",10,true);'
    subprocess.run(['php','-r',init],cwd=root,env=env,check=True)
    with socket.socket() as sock: sock.bind(('127.0.0.1',0)); port=sock.getsockname()[1]
    env['IEB_TEST_LOOPBACK_PORT']=str(port)
    env['IEB_TEST_GATE_PATH']=str(root/'maintenance.lock')
    with (directory/'server.log').open('w+') as log:
        server=subprocess.Popen(['php','-d','display_errors=0','-d','log_errors=0','-S',f'127.0.0.1:{port}','-t','sms-relay/public','sms-relay/public/index.php'],cwd=root,env=env,stdout=log,stderr=log)
        try:
            for _ in range(100):
                try:
                    with socket.create_connection(('127.0.0.1',port),timeout=.05): break
                except OSError: time.sleep(.05)
            else: raise RuntimeError('loopback relay did not start')
            result=subprocess.run(['php','tests/wp-sms-client.php'],cwd=root,env=env,capture_output=True,text=True,timeout=30)
            if result.returncode: raise RuntimeError(result.stdout+result.stderr)
            if 'PASS: loopback relay' not in result.stdout: raise RuntimeError('harness did not exercise real HTTP')
            log.flush(); log.seek(0); text=log.read()
            if key in text or '+15551230101' in text or '+15551230102' in text: raise RuntimeError('sensitive data appeared in server log')
            print(result.stdout.strip())
        finally:
            server.terminate(); server.wait(timeout=5)
