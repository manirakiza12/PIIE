# SMTP and Mailcow network contract

Status: repository change prepared; production attachment and delivery are
**NOT VERIFIED** until an authorised read-only check is run on the VPS.

## Evidence

The recovered production configuration shows:

- `piie-app` joined only `n8n_default` (public) and `piie-deploy_private`.
- Mailcow's postfix container is attached only to
  `mailcowdockerized_mailcow-network`, with the DNS alias `postfix` and port 25
  published on the host.
- The recovered Laravel `shared/.env` contained `MAIL_HOST=mailhog` /
  `MAIL_PORT=1025`, which cannot resolve from the app container.

A temporary `docker network connect piie-app mailcowdockerized_mailcow-network`
works until the app container is recreated. It is therefore not a fix.

## Permanent change

`deploy/container/compose.production.yaml` keeps the existing `public`
(`n8n_default`) and `private` (`piie-deploy_private`) networks and adds:

```yaml
mailcow:
  external: true
  name: mailcowdockerized_mailcow-network
```

`piie-app` joins `public`, `private` and `mailcow`. The explicit `name:` keys
pin the existing Docker network names, so renaming the compose project directory
does not create replacement networks.

`deploy/container/production.env.example` sets the internal relay:

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=postfix
MAIL_PORT=25
MAIL_ENCRYPTION=null
```

The application may still override these from `global_settings`; update those
rows to the same host/port if they are populated. Sender defaults live in
`config/mail.php` / `MAIL_FROM_ADDRESS`, not in deployment scripts.

## Mailcow netfilter caveat

Mailcow's `netfilter-mailcow` service can isolate the Mailcow bridge from other
Docker networks. Joining the network is necessary but may not be sufficient.
Before activation, verify from the host without exec'ing into the app container:

```sh
docker network inspect mailcowdockerized_mailcow-network \
  --format '{{range .Containers}}{{.Name}} {{end}}'
docker inspect piie-app \
  --format '{{range $k, $v := .NetworkSettings.Networks}}{{$k}} {{end}}'
docker run --rm --pull=never --network container:piie-app \
  --entrypoint getent "$(docker inspect -f '{{.Image}}' piie-app)" hosts postfix
docker run --rm --pull=never --network container:piie-app \
  --entrypoint php "$(docker inspect -f '{{.Image}}' piie-app)" -r \
  '$s=@fsockopen("postfix",25,$e,$m,5); echo $s?"SMTP TCP OK\n":"SMTP TCP FAIL: $m\n";'
```

If TCP fails while DNS resolves, read Mailcow's `DISABLE_NETFILTER_ISOLATION_RULE`
setting and its firewall rules. Prefer a narrowly scoped allow for the PIIE app
container; do not disable Mailcow isolation globally without review. The
host-published submission port (587/TLS with authenticated credentials) is the
fallback, but only if credentials are provisioned and stored in `shared/.env`
or `global_settings`, never in a tracked file.

## Verification and rollback

1. Apply the compose file during an approved maintenance window:
   `docker compose -f compose.production.yaml up -d --no-deps app`.
   This recreates only `piie-app`; it preserves `n8n_default`,
   `piie-deploy_private`, the database volume and the bind mount.
2. Verify the app still has all three networks and `/health` returns 200.
3. Verify postfix TCP reachability with the commands above.
4. Send one controlled message through the application's existing mail test or
   queued delivery path, then confirm acceptance in the Mailcow logs.
5. Rollback: restore the previous reviewed compose file and run
   `docker compose ... up -d --no-deps app`. The network attachment is removed
   with the container; no data is touched. Do not run `docker network rm` on
   any existing network.

No temporary `docker network connect` is used by any deployment script; the
`production-compose-network-sandbox.sh` test enforces that.
