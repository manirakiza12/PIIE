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

The repository is public. Do not use an ordinary Actions artifact as a private
inventory channel. See INVENTORY-EVIDENCE-PRIVACY.md before dispatch. No changes
to host pins or authorized keys are permitted merely by following these checks.

## Copyable console checks

Use only the browser console of the PIIE instance selected in the independently
authenticated Contabo account. Confirm instance ID, assigned IP and location in
the control panel against institutional records first; hostname alone is not
proof. Then run the following read-only commands (sudo uses the console's
authorized administrator; it does not enable root SSH):

```sh
date -u
hostname -f
ip -brief address
for key in /etc/ssh/ssh_host_*_key.pub; do
  sudo ssh-keygen -lf "$key" | awk '{print $1, $2, $NF}'
done
getent passwd piie
id piie
namei -l /home/piie/.ssh/authorized_keys
sudo stat -c '%n owner=%U:%G mode=%a' /home/piie /home/piie/.ssh /home/piie/.ssh/authorized_keys
sudo ssh-keygen -lf /home/piie/.ssh/authorized_keys | awk '{print $1, $2, $NF}'
sudo passwd -S piie
sudo chage -l piie
sudo /usr/sbin/sshd -T | grep -E '^(port|listenaddress|pubkeyauthentication|authorizedkeysfile|authorizedkeyscommand|authenticationmethods|permitrootlogin|passwordauthentication|allowusers|denyusers|allowgroups|denygroups|strictmodes) '
sudo ss -ltnp
sudo journalctl -u ssh -u sshd --since '2026-11-06 11:34:00 UTC' --until '2026-11-06 11:39:00 UTC' --no-pager
sudo -l -U piie
```

If piie is absent or the home differs, report that result and stop account-specific
checks; do not create or modify the account. AuthorizedKeysFile may point elsewhere;
inspect the effective configured file, not an assumed path. Review key options
(from=, command=, expiry-time=, restrict) privately in the console; report the
restriction names and relevant rejection reason without raw key lines or commands.
If journal records are missing, inspect the corresponding auth.log entries
privately. Do not dispatch another connection solely to manufacture a log entry.

For Match rules, read the rejected connection's source address from the private
auth log. Run `sshd -T -C` with that actual connection context (user, addr and,
where used by Match, host/laddr/lport); a localhost test cannot establish the
policy GitHub's runner received. Provide that sanitized context for the exact
follow-up command rather than substituting guessed values.

Return only: confirmation of the independently selected instance, public host-key
fingerprints, intended account/port, account/key-file owner/modes, whether the
configured v3 public fingerprint is present, relevant restriction names and the
sanitized auth rejection reason. Never share private keys, environment values,
raw auth logs or an unredacted sudo policy in the public repository.
