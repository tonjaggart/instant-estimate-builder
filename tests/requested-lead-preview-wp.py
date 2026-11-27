#!/usr/bin/env python3
"""Offline preview integration against the isolated WP/MySQL test installation.

Set IEB_TEST_WP and IEB_WP_CLI to the disposable fixture and WP-CLI phar.
No network or real mail is allowed by lead-unicode-wp.php.
"""
import os
from pathlib import Path
import subprocess
import tempfile

root = Path(__file__).resolve().parents[1]
wp = Path(os.environ['IEB_TEST_WP']).resolve()
cli = Path(os.environ['IEB_WP_CLI']).resolve()
if not str(wp).endswith('/ieb-sms-isolated-db/wp'):
    raise RuntimeError('A disposable WordPress fixture is required')
config = (wp / 'wp-config.php').read_text()
if "'ieb_test'" not in config or '/ieb-sms-isolated-db/mysql.sock' not in config or "'WP_HTTP_BLOCK_EXTERNAL', true" not in config:
    raise RuntimeError('Unsafe WordPress configuration')
source = (root / 'tests/lead-unicode-wp.php').read_text()
for title, service in [('Gutter Replacement', 'Gutter Replacement'), ('Roofing Estimate', 'Roofing')]:
    text = source.replace("'post_title'=>'Unicode test'", "'post_title'=>'" + title + "'")
    marker = '    echo "PASS: WordPress-slashed AJAX request'
    injection = '''    require_once %s;
    $input=['name'=>(string)get_post_meta($lead_id,'first_name',true),'phone'=>(string)get_post_meta($lead_id,'phone',true),'zip'=>(string)get_post_meta($lead_id,'zip_code',true),'service'=>(string)get_the_title((int)get_post_meta($lead_id,'_hgm_form_id',true)),'low'=>(string)get_post_meta($lead_id,'_hgm_estimate_low',true),'high'=>(string)get_post_meta($lead_id,'_hgm_estimate_high',true)];
    $body=ieb_sms_requested_preview($input);
    check_ieb($body==="New %s Lead:\\nTest\\n\\$7,700 - \\$17,700\\n555-0100\\n00000",'exact five-line preview differs');
    $sent=[]; $fake=function($to,$message) use (&$sent) { $sent[]=$message; }; $fake('+12025550101',$body); check_ieb($sent===[$body],'fake provider body mismatch');
''' % (repr(str(root / 'sms-relay/lead-format.php')), service)
    if marker not in text:
        raise RuntimeError('WordPress fixture changed')
    text = text.replace(marker, injection + marker)
    text = text.replace("if (!defined('HGM_PLUGIN_PATH')) { define('HGM_PLUGIN_PATH', dirname(__DIR__) . '/'); }", "if (!defined('HGM_PLUGIN_PATH')) { define('HGM_PLUGIN_PATH', " + repr(str(root) + '/') + "); }")
    print('FORM:', title, flush=True)
    with tempfile.NamedTemporaryFile(mode='w', suffix='.php', dir=os.environ['TMPDIR']) as fixture:
        fixture.write(text)
        fixture.flush()
        subprocess.run(['php', str(cli), '--path=' + str(wp), '--skip-plugins', '--skip-themes', 'eval-file', fixture.name], check=True)
