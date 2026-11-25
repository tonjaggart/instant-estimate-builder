#!/usr/bin/env python3
import http.server, os, pathlib, subprocess, threading, urllib.parse
root=pathlib.Path(__file__).resolve().parent.parent
class Fake(http.server.BaseHTTPRequestHandler):
    received=[]; status=201
    def log_message(self,*args): pass
    def do_POST(self):
        self.received.append(urllib.parse.parse_qs(self.rfile.read(int(self.headers['Content-Length'])).decode()))
        self.send_response(self.status); self.send_header('Content-Type','application/json'); self.end_headers()
        self.wfile.write(b'{"sid":"SM11111111111111111111111111111111","status":"queued"}' if self.status==201 else b'{"message":"fake error","code":12345}')
server=http.server.HTTPServer(('127.0.0.1',0),Fake); thread=threading.Thread(target=server.serve_forever,daemon=True); thread.start()
try:
    env=dict(os.environ,IEB_FAKE_PORT=str(server.server_port)); worker=root/'tests/sms-relay-twilio-worker.php'
    run=lambda: subprocess.run(['php',str(worker)],env=env,capture_output=True,text=True,check=True)
    res=run(); assert res.stdout=='accepted' and res.stderr=='', (res.stdout,res.stderr)
    assert Fake.received[0]['To']==['+12025550101'] and Fake.received[0]['Body']==['Taggart Media Group - New estimate: Synthetic Name | +12025550102 | Test'] and Fake.received[0]['From']==['+12025550100']
    Fake.status=500; res=run(); assert res.stdout=='unknown' and res.stderr=='', (res.stdout,res.stderr)
finally: server.shutdown(); thread.join(); server.server_close()
print('PASS: SDK adapter posts to loopback fake; ambiguous error stays unknown with no PII logs')
