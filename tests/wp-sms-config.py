#!/usr/bin/env python3
"""Validate fixed HTTPS relay host boundary without any network access."""
import os, pathlib, subprocess
root=pathlib.Path(__file__).resolve().parent.parent
program=r'''function add_action(...$a) {} define('IEB_SMS_RELAY_HOST',getenv('TEST_HOST')); define('IEB_SMS_INSTALLATION','test_install'); define('IEB_SMS_SECRET',str_repeat('a',64)); require 'includes/sms-client.php'; echo ieb_sms_configured() ? 'yes' : 'no';'''
cases={'relay.example.test':'yes','http://relay.example.test':'no','relay.example.test:443':'no','relay.example.test/path':'no','relay.example.test@evil.test':'no','relay.example.test?x=1':'no','127.0.0.1':'no','localhost':'no','RELAY.EXAMPLE.TEST':'no','relay.example.test\n':'no'}
for host,want in cases.items():
    result=subprocess.run(['php','-r',program],cwd=root,env=dict(os.environ,TEST_HOST=host),capture_output=True,text=True,check=True)
    if result.stdout!=want: raise AssertionError(repr((host,want,result.stdout,result.stderr)))
print('PASS: configured-host injection, scheme, port, path and IP rejected without network')
