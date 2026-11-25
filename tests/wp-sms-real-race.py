#!/usr/bin/env python3
"""Run against the dedicated disposable WP/DB created for tests/wp-sms-real-core.php."""
import os, pathlib, subprocess, tempfile
root=pathlib.Path(__file__).resolve().parent
wp=os.environ['IEB_TEST_WP']
base=['php','-d','error_reporting=24575',str(root/'wp-sms-real-core.php')]
with tempfile.TemporaryDirectory(dir=os.path.dirname(wp)) as d:
    env={**os.environ,'IEB_RACE_DIR':d,'IEB_RACE_LOG':d+'/requests'}
    # Only the disposable DB guarded by wp-sms-real-core.php is touched.
    cleanup='require getenv("IEB_TEST_WP")."/wp-load.php"; if(DB_NAME!=="ieb_test" || strpos(DB_HOST,"ieb-sms-isolated-db/mysql.sock")===false) exit(2); delete_user_meta(1,"_ieb_sms_enrollment_1");'
    subprocess.run(['php','-r',cleanup],env=env,check=True)
    procs=[subprocess.Popen(base+['race',phone],env=env,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True) for phone in ('+15550000101','+15550000102')]
    for p in procs:
        out,err=p.communicate(timeout=20)
        if p.returncode: raise RuntimeError(out+err)
    verify='require getenv("IEB_TEST_WP")."/wp-load.php"; if(DB_NAME!=="ieb_test" || strpos(DB_HOST,"ieb-sms-isolated-db/mysql.sock")===false) exit(2); echo count(get_user_meta(1,"_ieb_sms_enrollment_1",false));'
    count=subprocess.check_output(['php','-r',verify],env=env,text=True)
    requests=pathlib.Path(d,'requests').read_text().splitlines()
    assert count=='1' and len(requests)==1, f'expected one durable state and one verification request; got {count} rows and {len(requests)} requests'
print('PASS: concurrent initial enrollment creates one state and one verification request')
