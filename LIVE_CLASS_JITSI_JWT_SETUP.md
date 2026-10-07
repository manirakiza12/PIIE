# Live Class Jitsi Moderator Token Setup

## Why this exists

Without this, every Jitsi meeting is embedded anonymously — nobody in the
call is authenticated, so Jitsi's server never learns who "the host" is.
The result: **"waiting for a moderator" can appear for everyone, including
the person who started the class.** A signed JWT asserting
`context.user.moderator = true` for the host is Jitsi's own documented fix
for this; there's no config-only workaround that reliably works on the
public `meet.jit.si` server.

Leave the `.env` keys below blank and nothing breaks — classes keep working
exactly as they do today (anonymous embed, no guaranteed moderator rights).
Fill them in and the class host gets real moderator controls automatically.

## Which option applies to you

**On ordinary shared hosting (cPanel, no Docker/VPS access) — use 8x8 JaaS.**
It's a hosted Jitsi-as-a-Service with a generous free tier; nothing to
install or run yourself, just an API credential in `.env`.

**If you already run your own Jitsi Meet server** — use the self-hosted
option instead (`jitsi-meet-tokens` prosody plugin).

## Option A — 8x8 JaaS (recommended for shared hosting)

1. Sign up at https://jaas.8x8.vc and create an API key from your JaaS
   console. You'll be given:
   - an **App ID** (looks like `vpaas-magic-cookie-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx`)
   - a **Key ID** (`kid`)
   - a downloadable **private key** (`.pk` / PEM file)

2. Add to `.env`:

   ```env
   JITSI_JWT_ALGORITHM=RS256
   JITSI_APP_ID=vpaas-magic-cookie-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
   JITSI_KID=your-key-id
   JITSI_PRIVATE_KEY="-----BEGIN PRIVATE KEY-----\nMIIEvQ...\n-----END PRIVATE KEY-----\n"
   ```

   The private key can be pasted as a single `.env` line with literal `\n`
   sequences (as above) — the app converts them back to real newlines
   automatically. If your `.env` editor supports real multi-line values,
   that also works unchanged.

   JWT 7 requires an RSA private key of at least 2048 bits for RS256.
   Malformed, non-RSA, or undersized keys are treated as not configured.

3. In Settings → Academic, set **Jitsi Base URL** to
   `https://8x8.vc/{your App ID}` (same App ID as above). The app already
   builds meeting URLs as `{base}/{room-slug}`, so this alone produces the
   `https://8x8.vc/{appId}/{room}` shape JaaS expects — no code changes
   needed.

4. `php artisan optimize:clear`, then start a class. Settings → Academic
   will show "Moderator token: Configured" once the keys are picked up.

## Option B — self-hosted Jitsi with `jitsi-meet-tokens`

1. Install the `jitsi-meet-tokens` prosody plugin on your Jitsi server and
   set a shared `app_id` / `app_secret` in its Prosody config.

2. Add to `.env`:

   ```env
   JITSI_JWT_ALGORITHM=HS256
   JITSI_APP_ID=your_app_id
   JITSI_APP_SECRET=your_app_secret
   ```

   Use a randomly generated secret of at least 32 bytes, with the same
   value configured in Prosody. JWT 7 rejects shorter HS256 keys; the app
   treats them as not configured and does not issue a moderator token.

3. In Settings → Academic, set **Jitsi Base URL** to your own server's
   domain (e.g. `https://meet.yourschool.org`).

4. `php artisan optimize:clear`.

## How the system behaves

- No `JITSI_APP_ID` (or, for RS256, missing `kid`/`private_key`; for HS256,
  missing `app_secret`): `App\Support\LiveClasses\JitsiTokenService`
  reports "not configured" — the meeting embeds exactly as it always has,
  no JWT attached.
- Once configured: every embedded Jitsi meeting is signed per-viewer. The
  actual class owner (or any staff who could edit the class —
  `LiveClassPolicy::update`) gets `moderator: true`; everyone else
  (students, other viewers) gets `moderator: false`.
- The room name inside the token always matches the specific class's
  meeting URL — a token for one class's room can't be reused to claim
  moderator rights in a different class's room.

## Quick verification

1. Fill in the `.env` keys for whichever option applies to you and run
   `php artisan optimize:clear`.
2. Settings → Academic should now show "Moderator token: Configured".
3. Start a class as a teacher — the join page should show a green
   "signed moderator token" notice instead of the earlier yellow warning.
4. Join the same class as a student in another browser/incognito window —
   the class should load directly with no "waiting for a moderator"
   screen on either side.
