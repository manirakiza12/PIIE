import difflib
import hashlib
import os
from pathlib import Path
import subprocess

key = str(Path(os.environ['USERPROFILE']) / '.ssh/piie_github_actions_v4')
installed = subprocess.run([
    'ssh', '-i', key, '-o', 'BatchMode=yes', '-o', 'IdentitiesOnly=yes',
    '-o', 'StrictHostKeyChecking=yes', '-o', 'HostKeyAlgorithms=ssh-ed25519',
    '-o', 'ConnectTimeout=15', 'piie@62.171.133.41',
    'cat /usr/local/sbin/piie-deploy-gate'], check=True, capture_output=True).stdout
approved = subprocess.run(['git', 'show', '909a393:deploy/remote/piie-deploy-gate.sh'],
                          check=True, capture_output=True).stdout
Path(__file__).with_name('installed-gate.raw.sh').write_bytes(installed)
print('Approved bytes SHA256:', hashlib.sha256(approved).hexdigest())
print('Installed bytes SHA256:', hashlib.sha256(installed).hexdigest())
print('Exact match:', approved == installed)
a, b = approved.decode().splitlines(), installed.decode().splitlines()
print('\n'.join(difflib.unified_diff(a, b, fromfile='PR233', tofile='installed')))
print('Non-comment lines identical:', [x for x in a if not x.lstrip().startswith('#')]
      == [x for x in b if not x.lstrip().startswith('#')])
