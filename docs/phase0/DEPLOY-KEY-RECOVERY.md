# Deploy key recovery

The last recorded failure (42211372383) passed client-key and configured host-pin
checks but failed public-key authentication before the first remote command.
This establishes authentication rejection, not its root cause. A host pin changed
without independent verification cannot prove that this was the intended server.

First complete HOST-IDENTITY-VERIFICATION.md through the trusted console. No
credential changes are authorized by this document.

On the verified host, inspect the following without editing:

```sh
getent passwd piie
namei -l /home/piie/.ssh/authorized_keys
ssh-keygen -lf /home/piie/.ssh/authorized_keys
sudo passwd -S piie
sudo journalctl -u ssh --since '2026-11-06 11:34:00 UTC' --until '2026-11-06 11:39:00 UTC' --no-pager
```

Keep logs private; report only the relevant rejection reason, account, ownership,
permissions and public fingerprints. Compare the authorized public-key fingerprint
with the client's configured identity:
`SHA256:HnARD+o96mEq/ZObScCf5YgfXvV7RW3qrN/aF3LI4LA`.
Inspect effective AuthorizedKeysFile, AllowUsers/DenyUsers, Match rules, account
expiry, login shell, key restrictions, and endpoint/port routing. Distinguish
wrong host, wrong account, missing key, key restrictions and sshd policy from logs.

Request approval for the smallest evidenced correction. Do not enable root SSH,
password authentication, or disable host verification. Do not blindly rotate
keys or delete repository secrets: first inventory secret names/scopes (never
values) and note that environment secrets override repository secrets.

If rotation is approved, generate a dedicated Ed25519 pair in a private location;
put only its public half in the verified deploy account's authorized_keys and
only its private half in the production environment secret. Update the pinned
client fingerprint in prepare-ssh.sh and piie-deploy.yml in a reviewed PR. Apply
only restrictions compatible with the actual workflow: do not invent a forced
command gate that has not been implemented and tested. Validate one read-only
connection, then revoke the old key under the approved recovery procedure.
Never attach private keys, .env files or unredacted auth logs to a PR.
