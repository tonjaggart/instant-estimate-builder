#!/usr/bin/env python3
"""Exercise actual loopback HTTP, not a production relay or real provider."""
import hashlib, hmac, json, os, pathlib, secrets, socket, subprocess, tempfile, time, urllib.error, urllib.request
root=pathlib.Path(__file__).resolve().parent.parent
router=root/'tests/sms-relay-http-fixture.php'
with tempfile.TemporaryDirectory(dir=os.environ.get('TMPDIR')) as tmp:
    key=secrets.token_hex(32); crypt=secrets.token_hex(32); ident='install_123'
    env=dict(os.environ,IEB_TEST_DB=str(pathlib.Path(tmp)/'relay.db'),IEB_TEST_KEY=key,IEB_TEST_CRYPT=crypt)
    with socket.socket() as sock:
        sock.bind(('127.0.0.1',0)); port=sock.getsockname()[1]
    server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}',str(router)],cwd=root,env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    def request(path, payload, nonce=None, key_override=None):
        body=json.dumps(payload,separators=(',',':')).encode(); stamp=str(int(time.time())); nonce=nonce or secrets.token_hex(16)
        text='\n'.join(['IEB1',ident,'POST',path,stamp,nonce,hashlib.sha256(body).hexdigest()])
        sig=hmac.new(bytes.fromhex(key_override or key),text.encode(),hashlib.sha256).hexdigest()
        req=urllib.request.Request(f'http://127.0.0.1:{port}'+path,data=body,method='POST',headers={'X-Installation':ident,'X-Timestamp':stamp,'X-Nonce':nonce,'X-Signature':sig,'Content-Type':'application/json'})
        try:
            with urllib.request.urlopen(req,timeout=3) as res: return res.status,json.loads(res.read())
        except urllib.error.HTTPError as exc: return exc.code,json.loads(exc.read())
    try:
        for _ in range(100):
            try:
                with socket.create_connection(('127.0.0.1',port),timeout=.05): break
            except OSError: time.sleep(.05)
        else: raise AssertionError('server not ready')
        consent={'phone':'+12025550101','actor':'admin_1','notice':'notice-v1'}
        assert request('/consent',consent)[0]==200
        event={'event':'event_1','phone':'+12025550101','lead':{'name':'Synthetic Person','phone':'+12025550102','service':'Synthetic Service'}}
        nonce=secrets.token_hex(16)
        assert request('/events',event,nonce)==(200,{'result':'queued'})
        assert request('/events',event,nonce)[0]==401
        assert request('/events',event)==(200,{'result':'duplicate'})
        assert request('/events',event,key_override=secrets.token_hex(32))[0]==401
        conflict=dict(event,lead=dict(event['lead'],name='Changed'))
        assert request('/events',conflict)[0]==409
        env2=dict(env,IEB_TEST_KEY=crypt,IEB_TEST_NOW=str(int(time.time())))
        out=subprocess.check_output(['php',str(root/'tests/sms-relay-worker-fixture.php'),'send'],env=env2,text=True)
        assert out=='accepted',out
    finally:
        server.terminate(); server.wait(timeout=5)
print('PASS: loopback signed HTTP consent/event, replay, wrong key, duplicate, conflict and fake worker')
