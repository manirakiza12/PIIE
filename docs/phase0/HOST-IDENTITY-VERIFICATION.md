# Trusted host identity verification

Production remains frozen. An SSH handshake, ssh-keyscan, or an existing GitHub
secret does not independently establish server identity. Historical fingerprints
conflict; none is endorsed by this remediation.

1. Sign in to Contabo through your established account, open the console for the
   contracted PIIE instance, and match its instance ID and assigned public IP to
   your account records. Record operator, UTC time, instance ID and IP privately.
2. In that console run these read-only commands:

   ```sh
   hostname -f
   ip -brief address
   ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub
   ssh-keygen -lf /etc/ssh/ssh_host_rsa_key.pub
   ssh-keygen -lf /etc/ssh/ssh_host_ecdsa_key.pub
   getent passwd piie
   ls -ld /home/piie /home/piie/.ssh /home/piie/.ssh/authorized_keys
   sudo sshd -T | grep -E '^(port|pubkeyauthentication|authorizedkeysfile|permitrootlogin|allowusers|denyusers|authenticationmethods) '
   ```

3. Compare host, port and account with production environment secret metadata
   through an authorized administrator. Do not print secret values into Actions.
   If the endpoint differs, investigate NAT, instance replacement and port routing
   before changing anything. For Match rules, inspect effective sshd configuration
   with `sshd -T -C user=piie,host=<runner-host>,addr=<runner-ip>` in the console.
4. Only after independent agreement and explicit credential-change approval,
   obtain the public host key from this console and store the exact host/port
   known_hosts entry in the protected production environment. Nonstandard ports
   use `[host]:port`. Never accept a key interactively or disable strict checking.
5. Record the approved fingerprint, provenance and reviewer in a private incident
   record. Update misleading repository comments only from that verified record.
   Then approve one read-only inventory run after this PR is reviewed and merged.

The new workflow runs only on main, requires the production environment approval,
shares the deployment lock and does not depend on PIIE_DEPLOY_ENABLED. Its SSH
commands read files and metadata; operating-system access/audit logs may update.
Review artifact access: inventories contain infrastructure paths and file hashes.
No database contents, environment values or raw cron commands are collected.
