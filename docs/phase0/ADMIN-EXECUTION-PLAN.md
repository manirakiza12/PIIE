# Administrator execution without root SSH

Status: design only. No sudo policy, Docker group membership, host files or
production services have been changed. First establish trusted console identity
and inspect the existing account and privilege path. Do not replace a working
reviewed mechanism without understanding it.

The current container-preflight.sh requires host UID 0. The current workflow
invokes preflight and runtime-deploy directly; using an ordinary piie account
will not satisfy that requirement. Adding piie to the docker group is not a
least-privilege solution: Docker documents that this grants root-level access.
Likewise, sudo bash, sudo docker, a wildcard sudo rule for uploaded scripts or
executing a user-writable script as root gives the deploy key broad host control.

## Recommended boundary

Keep SSH authentication under the dedicated unprivileged account. Install, only
after explicit approval through the trusted console, a small root-owned command
gate outside that account's writable directory tree, for example
/usr/local/sbin/piie-deploy-gate, with its reviewed script bundle under
/usr/local/lib/piie-deploy. Every path component must be root-controlled and not
writable by piie or its groups. The gate must not source or execute the workflow's
uploaded /home/piie/deployments/piie/bin scripts as root.

The gate accepts an explicit finite operation set and validates the exact argument
count, mode, release ID, approved commit, expected live SHA, artifact digest and
paths. It uses fixed absolute executables, a fixed safe environment/PATH, no eval,
no shell command supplied by the caller and no arbitrary Docker options. It
claims a safe copy of an uploaded artifact in a root-controlled staging area,
verifies it against independently approved digest/SHA metadata, and handles
symlinks and replacement races before handing it to the reviewed deployment code.
An attacker must not gain approval simply by supplying both archive and checksum.

Grant sudo only for this installed gate, with tightly constrained operations and
arguments, and validate the policy using visudo. Do not grant generic interpreter
or Docker access or permit caller-controlled environment overrides. For read-only
diagnostics requiring elevated visibility, use a separate fixed no-argument,
root-owned inventory helper and sanitized output; never sudo a script read from
SSH stdin. Deployment and inventory privileges must remain distinct.

Root-owned deployment code must also verify all files it reads/sources and
production paths it manipulates against account-write/symlink attacks. Preserve
the existing lock, backup checks, migration review, forward-only DB behavior and
rollback safeguards. Do not recreate containers, change mounts or migrate Docker
to rootless mode as part of this recovery. Application code remains in its
existing container runtime/user context.

Before installation, sandbox tests must prove rejection of unauthorized SHA,
digest mismatch, traversal, symlink/race substitution, extra args, environment
injection and arbitrary commands. A separate PR must wire the approved gate into
all workflow operations; host installation, sudo-policy change and that workflow
change need review/authorization. This document is not a claim that the gate
already exists or is safe to install untested.

## Console inspection only

```sh
id piie
sudo -l -U piie
namei -l /home/piie/deployments/piie/bin/container-preflight.sh
namei -l /home/piie/deployments/piie/bin/container-deploy.sh
sudo stat -c '%n owner=%U:%G mode=%a' /var/run/docker.sock
```

Keep sudo policy output private. Report whether an existing root-owned restricted
gate exists, its owner/modes and permitted operation names. Do not install a new
gate or alter privileges until the verified host evidence and proposal are approved.

Sources:
https://docs.docker.com/engine/install/linux-postinstall/
https://www.sudo.ws/docs/man/1.9.14/sudoers.man.pdf
