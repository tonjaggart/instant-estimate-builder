#!/usr/bin/env python3
"""No network: workers must remain disabled even if all send switches are true."""
import json
import os
from pathlib import Path
import subprocess
import tempfile

root = Path(__file__).resolve().parent.parent
with tempfile.TemporaryDirectory(dir=os.environ.get('TMPDIR')) as directory:
    config = Path(directory) / 'config.json'
    config.write_text(json.dumps({
        'live_send_enabled': True,
        'verification_send_enabled': True,
        # Intentionally invalid: reaching database/provider setup is a failure.
        'database': str(Path(directory) / 'missing.sqlite'),
        'encryption_key': '0' * 64,
    }))
    for worker in ('worker.php', 'verification-worker.php'):
        result = subprocess.run(
            ['php', str(root / 'sms-relay' / worker)],
            env=dict(os.environ, IEB_RELAY_CONFIG=str(config)),
            capture_output=True, text=True, timeout=10,
        )
        assert result.returncode != 0, (worker, result)
        assert result.stdout == '', (worker, result.stdout)
        assert result.stderr == 'Sending disabled: authorization recheck unavailable\n', (worker, result.stderr)
        assert not (Path(directory) / 'missing.sqlite').exists(), worker
print('PASS: both workers fail closed before database/provider setup even with send switches enabled')
