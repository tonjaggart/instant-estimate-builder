#!/usr/bin/env python3
"""Real front controller + SQLite + actual SDK, with loopback-only fake SMS provider."""
import base64, concurrent.futures, hashlib, hmac, http.server, json, os, pathlib, re, secrets, socket, sqlite3, subprocess, tempfile, threading, time, urllib.error, urllib.parse, urllib.request
root=pathlib.Path(__file__).resolve().parent.parent
with tempfile.TemporaryDirectory(dir=os.environ.get('TMPDIR')) as d:
    d=pathlib.Path(d); db=d/'relay.db'; cfg=d/'config.json'; a='install_a'; b='install_b'; keys={a:secrets.token_hex(32),b:secrets.token_hex(32)}; crypt=secrets.token_hex(32)
    token='synthetic-token'; account='AC'+'2'*32; sender='+15550000100'; hookurl='https://relay.example.test/v1/twilio'
    cfg.write_text(json.dumps({'database':str(db),'encryption_key':crypt,'installations':keys,'live_send_enabled':False,'twilio':{'auth_token':token,'account_sid':account,'sender':sender,'webhook_url':hookurl}}))
    env=dict(os.environ,IEB_RELAY_CONFIG=str(cfg))
    init='require "sms-relay/queue.php"; require "sms-relay/auth.php"; $c=json_decode(file_get_contents(getenv("IEB_RELAY_CONFIG")),true); $db=new PDO("sqlite:".$c["database"]); $q=new IebSmsQueue($db,hex2bin($c["encryption_key"])); $q->install(); ieb_auth_install_schema($db); $q->configureInstallation("install_a",20,true); $q->configureInstallation("install_b",20,true);'
    subprocess.run(['php','-r',init],cwd=root,env=env,check=True)
    servers=[]; logs=[]
    def freeport():
        with socket.socket() as s: s.bind(('127.0.0.1',0)); return s.getsockname()[1]
    try:
        for _ in range(4):
            port=freeport(); log=(d/f'{port}.log').open('w+'); logs.append(log)
            proc=subprocess.Popen(['php','-d','display_errors=0','-d','log_errors=0','-S',f'127.0.0.1:{port}','-t','sms-relay/public','sms-relay/public/index.php'],cwd=root,env=env,stdout=log,stderr=log); servers.append((port,proc))
        for port,proc in servers:
            for _ in range(100):
                try:
                    with socket.create_connection(('127.0.0.1',port),timeout=.05): break
                except OSError: time.sleep(.05)
            else: raise AssertionError('server not ready')
        def signed(path,data,ident=a,port=None):
            port=port or servers[0][0]; body=json.dumps(data,separators=(',',':')).encode(); ts=str(int(time.time())); nonce=secrets.token_hex(16); sig=hmac.new(bytes.fromhex(keys[ident]),'\n'.join(['IEB1',ident,'POST',path,ts,nonce,hashlib.sha256(body).hexdigest()]).encode(),hashlib.sha256).hexdigest()
            req=urllib.request.Request(f'http://127.0.0.1:{port}'+path,data=body,headers={'Content-Type':'application/json','X-Installation':ident,'X-Timestamp':ts,'X-Nonce':nonce,'X-Signature':sig},method='POST')
            try:
                with urllib.request.urlopen(req,timeout=8) as r: return r.status,json.loads(r.read())
            except urllib.error.HTTPError as e: return e.code,json.loads(e.read())
        class Provider(http.server.BaseHTTPRequestHandler):
            requests=[]
            def log_message(self,*args): pass
            def do_POST(self):
                self.requests.append(urllib.parse.parse_qs(self.rfile.read(int(self.headers['Content-Length'])).decode()))
                self.send_response(201); self.send_header('Content-Type','application/json'); self.end_headers(); self.wfile.write(b'{"sid":"SM11111111111111111111111111111111","status":"queued"}')
        provider=http.server.HTTPServer(('127.0.0.1',0),Provider); threading.Thread(target=provider.serve_forever,daemon=True).start()
        def dispatch(): return subprocess.run(['php','tests/sms-relay-verification-worker.php'],cwd=root,env=dict(env,IEB_FAKE_PORT=str(provider.server_port)),capture_output=True,text=True,check=True).stdout
        phone='+15550000101'; request={'request_id':secrets.token_hex(16),'phone':phone,'actor':'admin_1','notice':'admin-sms-v1','opt_in':True}
        assert signed('/v1/verification/request',dict(request,opt_in=False))[0]==400
        assert signed('/v1/verification/request',dict(request,opt_in='true'))[0]==400
        assert signed('/v1/consent',{'phone':phone,'actor':'admin_1','notice':'admin-sms-v1'})[0]==403
        assert signed('/v1/verification/request',request)==(202,{'result':'requested'})
        assert signed('/v1/verification/request',request)==(202,{'result':'requested'})
        assert signed('/v1/verification/confirm',{'request_id':request['request_id'],'code':'000000'})[0]==409
        assert dispatch()=='accepted'; assert dispatch()=='empty'; assert len(Provider.requests)==1
        msg=Provider.requests[0]; assert msg['To']==[phone] and 'Taggart Media Group' in msg['Body'][0] and 'STOP' in msg['Body'][0] and 'HELP' in msg['Body'][0]
        code=re.search(r'\b\d{6}\b',msg['Body'][0]).group(); confirm={'request_id':request['request_id'],'code':code}
        assert signed('/v1/verification/confirm',confirm,b)[0]==409
        assert signed('/v1/verification/confirm',confirm)[0]==200
        assert signed('/v1/verification/confirm',confirm)[0]==200
        event={'event':'event_1','phone':phone,'request_id':request['request_id'],'lead':{'name':'Synthetic','phone':'+15550000102','service':'Test'}}
        assert signed('/v1/events',event)[0]==200 and signed('/v1/events',event,b)[0]==409
        dispatched=subprocess.run(['php','tests/sms-relay-twilio-worker.php'],cwd=root,env=dict(env,IEB_FAKE_PORT=str(provider.server_port),IEB_FAKE_EXISTING_DB='1'),capture_output=True,text=True,check=True)
        assert dispatched.stdout=='accepted' and not dispatched.stderr and len(Provider.requests)==2
        assert Provider.requests[1]['To']==[phone] and 'Taggart Media Group - New estimate: Synthetic' in Provider.requests[1]['Body'][0]
        inbound={'From':phone,'To':sender,'Body':'STOP','MessageSid':'SM'+'1'*32,'AccountSid':account}
        sig=base64.b64encode(hmac.new(token.encode(),(hookurl+''.join(k+inbound[k] for k in sorted(inbound))).encode(),hashlib.sha1).digest()).decode()
        hook=urllib.request.Request(f'http://127.0.0.1:{servers[0][0]}/v1/twilio',data=urllib.parse.urlencode(inbound).encode(),headers={'Content-Type':'application/x-www-form-urlencoded','X-Twilio-Signature':sig},method='POST')
        with urllib.request.urlopen(hook,timeout=5) as response: assert response.status==200
        assert signed('/v1/events',dict(event,event='event_2'))[0]==409
        assert signed('/v1/verification/request',dict(request,request_id=secrets.token_hex(16)))[0]==202
        assert dispatch()=='empty' and len(Provider.requests)==2
        # Concurrent requests with different IDs must collectively honor the global number limit.
        target='+15550000103'; requests=[dict(request,request_id=secrets.token_hex(16),phone=target) for _ in range(12)]
        with concurrent.futures.ThreadPoolExecutor(max_workers=12) as pool:
            out=list(pool.map(lambda pair:signed('/v1/verification/request',pair[1],port=servers[pair[0]%4][0]),enumerate(requests)))
        assert all(status==202 for status,_ in out)
        with sqlite3.connect(db) as conn:
            rows=conn.execute("SELECT state,count(*) FROM ieb_sms_verifications WHERE phone_hash=(SELECT phone_hash FROM ieb_sms_verifications WHERE request_id=?) GROUP BY state",(requests[0]['request_id'],)).fetchall()
            assert dict(rows)=={'blocked':9,'queued':3},rows
            raw=' '.join(map(str,conn.execute('SELECT * FROM ieb_sms_verifications').fetchall())); assert phone not in raw and code not in raw
        provider.shutdown(); provider.server_close()
        for log in logs:
            log.flush(); log.seek(0); text=log.read(); assert phone not in text and code not in text and keys[a] not in text
    finally:
        for _,proc in servers: proc.terminate(); proc.wait(timeout=5)
        for log in logs: log.close()
print('PASS: real signed HTTP, real SQLite, loopback fake-provider OTP and concurrent global rate limit')
