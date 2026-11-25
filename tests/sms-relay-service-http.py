#!/usr/bin/env python3
"""Exercise the real relay HTTP front controller on loopback with synthetic keys."""
import hashlib,hmac,base64,json,os,pathlib,secrets,socket,subprocess,tempfile,time,urllib.request,urllib.error,urllib.parse,http.server,threading,sqlite3
root=pathlib.Path(__file__).resolve().parent.parent
with tempfile.TemporaryDirectory(dir=os.environ.get('TMPDIR')) as d:
    cfg=pathlib.Path(d)/'config.json'; db=pathlib.Path(d)/'db.sqlite'; ident='install_123'; secret=secrets.token_hex(32); crypt=secrets.token_hex(32); token='fake-token-0123456789'; account='AC'+'2'*32; sender='+12025550100'; url='https://relay.example.test/v1/twilio'
    cfg.write_text(json.dumps({'database':str(db),'encryption_key':crypt,'installations':{ident:secret},'twilio':{'account_sid':account,'auth_token':token,'sender':sender,'webhook_url':url}}))
    env=dict(os.environ,IEB_RELAY_CONFIG=str(cfg)); php='require "sms-relay/queue.php"; require "sms-relay/auth.php"; $c=json_decode(file_get_contents(getenv("IEB_RELAY_CONFIG")),true); $d=new PDO("sqlite:".$c["database"]); $q=new IebSmsQueue($d,hex2bin($c["encryption_key"])); $q->install(); ieb_auth_install_schema($d); $q->configureInstallation("install_123",5,true);'
    subprocess.run(['php','-r',php],cwd=root,env=env,check=True)
    with socket.socket() as s: s.bind(('127.0.0.1',0)); port=s.getsockname()[1]
    log=pathlib.Path(d)/'server.log'
    with log.open('w+') as logfile:
      proc=subprocess.Popen(['php','-d','display_errors=0','-d','log_errors=0','-S',f'127.0.0.1:{port}','-t','sms-relay/public','sms-relay/public/index.php'],cwd=root,env=env,stdout=logfile,stderr=logfile)
      def post(path,body,headers):
        req=urllib.request.Request(f'http://127.0.0.1:{port}'+path,data=body,headers=headers,method='POST')
        try:
          with urllib.request.urlopen(req,timeout=5) as r: return r.status,r.read().decode()
        except urllib.error.HTTPError as e: return e.code,e.read().decode()
      def signed(path,data,nonce=None):
        body=json.dumps(data,separators=(',',':')).encode(); ts=str(int(time.time())); nonce=nonce or secrets.token_hex(16); text='\n'.join(['IEB1',ident,'POST',path,ts,nonce,hashlib.sha256(body).hexdigest()]); sig=hmac.new(bytes.fromhex(secret),text.encode(),hashlib.sha256).hexdigest()
        return post(path,body,{'Content-Type':'application/json','X-Installation':ident,'X-Timestamp':ts,'X-Nonce':nonce,'X-Signature':sig})
      def hook(data, bad=False):
        source=url+''.join(k+data[k] for k in sorted(data)); sig=base64.b64encode(hmac.new(token.encode(),source.encode(),hashlib.sha1).digest()).decode(); return post('/v1/twilio',urllib.parse.urlencode(data).encode(),{'Content-Type':'application/x-www-form-urlencoded','X-Twilio-Signature':('bad' if bad else sig)})
      try:
        for _ in range(100):
          try:
            with socket.create_connection(('127.0.0.1',port),timeout=.05): break
          except OSError: time.sleep(.05)
        else: raise AssertionError('server not ready')
        phone='+12025550101'; event={'event':'event_1','phone':phone,'lead':{'name':'SyntheticPrivateName','phone':'+12025550102','service':'Test'}}
        assert signed('/v1/events',event)[0]==409
        assert signed('/v1/consent',{'phone':phone,'actor':'admin_1','notice':'notice-v1'})[0]==403
        # Bypass the API only to seed a synthetic queue fixture, not to emulate enrollment.
        seed='require "sms-relay/queue.php"; $c=json_decode(file_get_contents(getenv("IEB_RELAY_CONFIG")),true); $d=new PDO("sqlite:".$c["database"]); $q=new IebSmsQueue($d,hex2bin($c["encryption_key"])); $q->consent("install_123",getenv("IEB_FIXTURE_PHONE"),"fixture","notice-v1",time());'
        subprocess.run(['php','-r',seed],cwd=root,env=dict(env,IEB_FIXTURE_PHONE=phone),check=True)
        nonce=secrets.token_hex(16); assert signed('/v1/events',event,nonce)[0]==200
        proc.terminate(); proc.wait(timeout=5)
        proc=subprocess.Popen(['php','-d','display_errors=0','-d','log_errors=0','-S',f'127.0.0.1:{port}','-t','sms-relay/public','sms-relay/public/index.php'],cwd=root,env=env,stdout=logfile,stderr=logfile)
        for _ in range(100):
          try:
            with socket.create_connection(('127.0.0.1',port),timeout=.05): break
          except OSError: time.sleep(.05)
        else: raise AssertionError('restart not ready')
        assert signed('/v1/events',event,nonce)[0]==401
        assert json.loads(signed('/v1/events',event)[1])['result']=='duplicate'
        class Fake(http.server.BaseHTTPRequestHandler):
          requests=[]
          def log_message(self,*args): pass
          def do_POST(self):
            self.requests.append(self.rfile.read(int(self.headers['Content-Length'])))
            self.send_response(201); self.send_header('Content-Type','application/json'); self.end_headers(); self.wfile.write(b'{"sid":"SM11111111111111111111111111111111","status":"queued"}')
        provider=http.server.HTTPServer(('127.0.0.1',0),Fake); thread=threading.Thread(target=provider.serve_forever,daemon=True); thread.start()
        try:
          worker_env=dict(env,IEB_FAKE_PORT=str(provider.server_port),IEB_FAKE_EXISTING_DB='1')
          sent=subprocess.run(['php',str(root/'tests/sms-relay-twilio-worker.php')],env=worker_env,capture_output=True,text=True,check=True)
          assert sent.stdout=='accepted' and not sent.stderr and len(Fake.requests)==1
          with sqlite3.connect(db) as conn: assert conn.execute("SELECT state FROM ieb_sms_messages WHERE event='event_1'").fetchone()==('accepted',)
          payload=urllib.parse.parse_qs(Fake.requests[0].decode()); assert payload['Body'][0].startswith('Taggart Media Group - New estimate: SyntheticPrivateName')
        finally: provider.shutdown(); thread.join(); provider.server_close()
        inbound={'From':phone,'To':sender,'Body':'STOP','MessageSid':'SM'+'1'*32,'AccountSid':account}
        assert hook(inbound,True)[0]==403; assert hook(inbound)[0]==200
        proc.terminate(); proc.wait(timeout=5)
        proc=subprocess.Popen(['php','-d','display_errors=0','-d','log_errors=0','-S',f'127.0.0.1:{port}','-t','sms-relay/public','sms-relay/public/index.php'],cwd=root,env=env,stdout=logfile,stderr=logfile)
        for _ in range(100):
          try:
            with socket.create_connection(('127.0.0.1',port),timeout=.05): break
          except OSError: time.sleep(.05)
        else: raise AssertionError('webhook restart not ready')
        assert hook(inbound)[0]==200
        with sqlite3.connect(db) as conn: assert conn.execute("SELECT count(*) FROM ieb_sms_consent_audit WHERE action='stop'").fetchone()==(1,)
        event['event']='event_2'; assert signed('/v1/events',event)[0]==409
        inbound['Body']='HELP'; inbound['MessageSid']='SM'+'3'*32; assert 'support@taggartmediagroup.com' in hook(inbound)[1]
        sig=base64.b64encode(hmac.new(token.encode(),(url+''.join(k+inbound[k] for k in sorted(inbound))).encode(),hashlib.sha1).digest()).decode()
        duplicate=b'Body=STOP&'+urllib.parse.urlencode(inbound).encode()
        assert post('/v1/twilio',duplicate,{'Content-Type':'application/x-www-form-urlencoded','X-Twilio-Signature':sig})[0]==400
      finally:
        proc.terminate(); proc.wait(timeout=5)
      logfile.seek(0); logs=logfile.read(); assert 'SyntheticPrivateName' not in logs and phone not in logs and secret not in logs and token not in logs
print('PASS: real loopback HTTP signed API, replay, durable STOP and HELP; no PII in process logs')
