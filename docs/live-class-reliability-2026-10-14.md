# Live-class repairs for the next deployment

The embedded classroom ended attendance when a browser tab became hidden, even when the participant remained in the call. Its fallback link omitted the user's signed Jitsi identity, and its guidance told students to sign in as host. The fixed 520px minimum room height also pushed meeting controls below smaller screens.

Changes:

- Record departure on conference exit or page departure, with one request per attendance record. Tab switches, phone app switches and screen locks do not end attendance.
- Use a CSRF-protected keepalive request when a leave beacon cannot be queued. Failed delivery can retry on another departure event.
- Preserve the current authorized user's JWT in both external fallbacks, replacing any stored token while retaining other room options. Student tokens remain participant-only. Links suppress referrers and opener access.
- Give lecturers and participants separate instructions. Only report a signed identity when a token was actually generated.
- Explain public Jitsi host sign-in on class detail screens without changing authorization or join-window rules.
- Fit the room below the actual header and controls; respond to browser and mobile viewport resizing. Provide a readable external fallback if the provider script is unavailable or fails to initialize.

Validation:

- Final scoped backend suite: 385 tests, 2,148 assertions, no failures, 21 skipped. This includes scheduling, provider failures, lecturer/student authorization, materials, attendance and reminders, with isolated databases and fake providers.
- Full browser-logic suite: 42 tests passed, including nine classroom regression cases.
- Route/template audit: 1,324 routes and 744 templates, no invalid actions or template syntax errors.
- Actual rendered classroom preview checked at 375, 768 and 1440 pixels with a mocked meeting provider. No horizontal overflow; consistent Nunito font. The preview does not create a real meeting or test microphone/camera transmission.

Live configuration was inspected read-only: Jitsi uses public meet.jit.si without moderator credentials, and installation-wide Zoom and Google Meet API credentials are absent. Public Jitsi requires the lecturer to sign in to start the room. Automatic provider hosting needs legitimate provider configuration; these repairs do not invent credentials or modify production settings.

Official provider references: [Jitsi hosting](https://jitsi.github.io/handbook/docs/user-guide/user-guide-start-a-jitsi-meeting/), [Jitsi iframe integration](https://jitsi.github.io/handbook/docs/dev-guide/dev-guide-iframe/), [Jitsi JWT URL support](https://github.com/jitsi/lib-jitsi-meet/blob/master/doc/tokens.md).

Prepared on `fix/live-class-room-reliability`. No migrations are required. Include the new `public/assets/js/live-class-room.js` with the controller and view changes in the next release. No live deployment was performed for this work.
