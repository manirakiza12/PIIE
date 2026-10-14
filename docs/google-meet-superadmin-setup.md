# Google Meet setup from Super Admin

After this release, open **Super Admin → Settings → Google Meet Setup**. No server file or environment editing is needed for the new setup flow.

1. In Google Cloud, enable the Google Calendar API, configure the OAuth consent screen, and create a **Web application** OAuth client. Copy the callback URLs displayed in the setup screen into Google's authorized redirect URIs. Both the configured domain and its www alias are shown; the login flow stays on the matching trusted hostname to preserve the session.
2. Select the institution allowed to use the shared calendar. Paste the OAuth Client ID and Client Secret, or import Google's Web-client JSON. Save with integration enabled. A regular API key or Desktop-client JSON is not accepted.
3. Connect the institution's Google account and approve calendar access. Use **Verify calendar access** to check the Calendar API and selected calendar. Verification does not create a meeting or invite anyone.
4. Administrators can schedule Google Meet classes for the selected institution. The meeting owner must sign in to the connected Google account to host. Lecturers connect their own Google accounts from Live Classes when scheduling their course offerings.

The client secret is encrypted in the database. OAuth access and refresh tokens retain the existing encryption. Secrets are not rendered or flashed on validation failure; imported files are not stored publicly. Changes are restricted to Super Admin and audited without secret values. Blank secrets preserve existing credentials; changing the Client ID requires a new secret and invalidates prior grants so users reconnect. Disabling Google Meet stops the integration without rotating the application key.

When Google accepts an event before preparing its Meet link, the class keeps the event ID, account owner and calendar. A background scheduler refreshes pending links every five minutes without creating duplicate events. The existing production cron already invokes the scheduler inside the app container.

Existing private-file OAuth installations and legacy environment credentials remain supported when no database setup exists. Saved database configuration takes precedence. The institution account is usable only for its selected school; the lecturer Offering workflow never silently substitutes it for a missing personal connection.

The migration creates `google_meet_settings` and adds nullable account-owner/calendar fields to live classes. Existing records are not rewritten. Existing encryption keys and payment configuration are unchanged.

Local validation: 433 Google/live-class/Jitsi tests, 2,319 assertions, no failures; 21 skipped. The 13 new setup/scheduling checks pass with fake Google responses, including authorization, encrypted storage, credential rotation, JSON import, state binding, refresh-token ownership, trusted-host callback continuity, read-only verification, real controller scheduling and pending-event refresh. Rendered setup screen checked at mobile, tablet and desktop widths. Route/template audit passes (1,328 routes; 745 templates).

Google Cloud consent must also permit the connecting account. External apps in Testing can have seven-day refresh-token expiration; follow Google's production publishing and verification requirements for sustained access. No real Google account or live credentials were connected during development.

Official references: [Google OAuth for web applications](https://developers.google.com/identity/protocols/oauth2/web-server), [Google Calendar conference creation](https://developers.google.com/workspace/calendar/api/guides/create-events).
