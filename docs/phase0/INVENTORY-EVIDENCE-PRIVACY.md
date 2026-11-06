# Private production inventory evidence

The repository is public. Ordinary Actions artifacts are not an approved private
channel for production infrastructure reports. Do not dispatch the original
inventory workflow until this encryption correction is reviewed and merged, or
collect the reviewed script's output privately through the trusted console.
No original inventory run has been dispatched by this recovery work.

The corrected workflow validates an explicitly pinned recipient certificate
before SSH, captures remote stdout and stderr in a runner-only private file,
encrypts complete or partial reports with OpenSSL CMS AES-256-GCM, and uploads
only production-inventory.cms. Cleanup removes plaintext, certificate and SSH
material from the ephemeral runner. Encryption failure prevents upload. The
SSH/verification step still fails when access or backup evidence fails; a retained
encrypted partial report does not mean inventory passed.

## Operator setup (separate from SSH credentials)

An authorized operator must approve this evidence channel and retain the private
decryption key on encrypted storage outside the repository and Actions. Neither
the private key nor its passphrase belongs in a GitHub secret, variable or chat.
These instructions create no production credentials and do not rotate SSH keys.

If the operator chooses a new recipient, run these commands from a private
directory on that encrypted storage. OpenSSL prompts for the private-key
passphrase; never put the passphrase in a command argument:

```sh
umask 077
openssl genpkey -algorithm RSA -aes-256-cbc -pkeyopt rsa_keygen_bits:3072 -out piie-inventory-private.pem
openssl req -new -x509 -sha256 -days 30 -key piie-inventory-private.pem -subj /CN=PIIE-inventory-evidence -out piie-inventory-recipient.pem
openssl x509 -in piie-inventory-recipient.pem -outform DER | openssl dgst -sha256
```

Through GitHub Settings > Environments > production > Environment variables,
an authorized operator sets the following PUBLIC recipient configuration:

- PIIE_INVENTORY_RECIPIENT_CERT: the complete public recipient PEM certificate.
- PIIE_INVENTORY_RECIPIENT_SHA256: its DER SHA256 digest, 64 lowercase hex
  characters only (omit the OpenSSL label and spaces).

Independently compare the fingerprint and prove local access to the corresponding
private key before approving a run. The certificate must remain valid for at
least 24 hours. These variables are prerequisites, not permission to dispatch:
trusted server identity, working approved SSH access, and production environment
review are still required. Keep PIIE_DEPLOY_ENABLED=false.

After approved collection, download only the encrypted artifact. On approved
private storage, decrypt with:

```sh
umask 077
openssl cms -decrypt -binary -inform DER -in production-inventory.cms -recip piie-inventory-recipient.pem -inkey piie-inventory-private.pem -out production-inventory.log
```

Check the decryption exit status before reading the output. Do not attach the
decrypted report to a public PR or paste its infrastructure details into public
logs. Review privately and share only sanitized gate results. If Codex needs the
decrypted local file, authorize its private destination and local access first.
This inventory authorization never authorizes DB/upload backup transfer.

Reference: https://docs.openssl.org/3.0/man1/openssl-cms/
