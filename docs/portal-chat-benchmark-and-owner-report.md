# PIIE portal messaging: benchmark, implementation and owner report

15 October 2026. Status: local implementation and verification; not deployed.

## Outcome

The first messaging release replaces the duplicated chat logic across seven portals with one authorisation and delivery layer. It implements the security, mobile usability, reliable direct messaging and reporting/administration phases of the proposed roadmap. Existing conversation and message records are retained. Admissions, registration numbers, enrolments, results, payments and marks are not changed.

The immediate policy is student-to-authorised-staff messaging. Student-to-student, student-to-parent and parent-to-parent messaging are disabled. Guardians have a separate staff-contact workflow and gain no access to student conversations. Existing conversations that no longer satisfy the contact policy remain visible to their original participants as read-only history. No new messages can be added to them.

## Reference-platform comparison

This is a comparison of documented capabilities, not hands-on testing or a claim that every feature is included in every subscription. Product capabilities and plan limits vary. “Not established” means the reviewed primary documentation did not establish that capability; it does not mean the product lacks it.

| Capability | Remind | ParentSquare / StudentSquare | Apptegy Two-Way | Bloomz | Smart School by QDOCS |
|---|---|---|---|---|---|
| Two-way communication | Individual and group messaging | Individual messages within classes/groups | Two-way school messaging | Direct messages to contacts | Contact-based chat |
| Roles and oversight | School oversight and privacy controls | School controls and administrative visibility | School moderation and visibility | School/class contact context | Admin contact discovery documented |
| Mobile access | Web and mobile app | Web and app on multiple devices | Mobile app and browser | App, SMS and email channels | Chat workflow documented; mobile parity not established |
| Notifications | SMS participation documented | Text, email, app and voice | SMS notification documented | Multiple notification channels | Not established from reviewed chat article |
| Retained history | Recorded history and downloads | Auditable communication records | Preserved/archived messages | Not established from reviewed sources | Existing chat/new-message view documented |
| Safety and moderation | Blocking and reporting | Administrative visibility; moderation offering varies | Manual reports and optional proactive moderation | Not established from reviewed sources | Not established from reviewed chat article |
| Attachments | Files/photos; documented size and plan limits | Not established from the reviewed pages | Not established from the reviewed page | Not established from the reviewed pages | Not established from the reviewed chat article |
| Groups / context | Class and activity messaging | Classes and activity groups | Class Stream | School/classroom context | Staff/student contact chat documented |
| Translation | Two-way translation documented | Two-way translation documented | Automatic translation documented | Two-way message/UI translation documented | Not established from reviewed chat article |

Primary references reviewed:

- [Remind trust and safety](https://help.remind.com/hc/en-us/articles/203744719-Trust-and-safety-on-Remind): recorded history, downloads, blocks, reports and age-related controls. [Remind file sharing](https://help.remind.com/hc/en-us/articles/202550638--How-do-I-share-a-photo-or-other-files) documents web/app attachments and limits. [Remind engagement](https://www.remind.com/resources/distance-engagement) describes replies over text. [Remind translation overview](https://help.remind.com/hc/article_attachments/17937276974989) describes two-way translation.
- [ParentSquare StudentSquare](https://www.parentsquare.com/platform/studentsquare/): student messaging, classes/groups, multi-device access, school controls and auditable records. [ParentSquare contactability](https://www.parentsquare.com/resources/reaching-every-family-contactability/) describes translation and direct-message tracking. [ParentSquare FAQs](https://www.parentsquare.com/resources/faqs/) describes notification channels. PIIE's guardian separation is a local policy, not a claim that ParentSquare uses the same policy.
- [Apptegy Two-Way Messaging](https://www.apptegy.com/solutions/two-way-messaging/): browser/mobile use, SMS, translation, reporting, moderation and archives.
- [Bloomz translation](https://www.bloomz.com/product-translation/) and [Bloomz contact messaging](https://support.bloomz.com/support/solutions/articles/62000080435-messaging-my-contacts): two-way communication, school/classroom context and translation across channels. [Older Bloomz support documentation](https://support.bloomz.com/support/solutions/articles/62000199779-does-bloomz-translate-into-other-languages-) describes premium message translation; confirm current package terms rather than assuming all features are free.
- [QDOCS Smart School contact chat](https://smart-school.in/article/how-to-add-contact-in-chat): contact search/add, staff/student conversations and sending/viewing messages. No inference is made about unrelated products with a similar name.

## What is implemented

| Area | Previous PIIE behaviour | Implemented behaviour |
|---|---|---|
| Access control | Inconsistent ownership and recipient checks | School and participant checks on every read, poll, send, read acknowledgement, report, archive and block request; all seven legacy portals delegate to the same controller |
| Contact policy | Broad name search | Active institutional staff contacts for students; self, peer, parent and foreign-school contacts rejected server-side |
| Support staff | No unified rule | Admin/lecturer/finance/library/welfare contacts by core role; additional support staff need an institution-scoped `messaging.use` grant |
| Starting conversations | GET could create duplicate directional threads | CSRF-protected POST; canonical existing thread reused in either direction; historical GET links only redirect to contact discovery |
| Mobile discovery | Search hidden on phones | Visible labelled search, available contacts, names, roles, photos, recent conversations and empty-state instructions |
| Delivery | Reload to see messages | 8-second polling in visible conversation; drafts and focus retained; message IDs prevent duplicate rendering and skipped arrivals |
| Sending | Minimal validation | Trimmed non-empty text, 5,000-character limit, CSRF, per-user/action rate limits and idempotent submission IDs; recipient/sender/school derived server-side |
| Read state | Broad updates | Explicit acknowledgement scoped to incoming messages in the owned conversation; read-through cursor and stored-message confirmation |
| Notifications | Limited static indicators | Scoped unread badge refreshed every 30 seconds and coalesced notification-centre entries; reports notify eligible reviewers |
| Offline email | None | Database outbox; delayed reminder after two minutes, deferred while recipient is viewing Messages; rate limited to roughly one per ten minutes per recipient/conversation, with bounded retries |
| Email privacy | No reminder workflow | Reminder includes only a portal sign-in link, not message content, sender contact details or report reasons |
| History | Unclear archive behaviour | Personal archives, participant history search, earlier-message paging; new messages restore the inbox; no message editing/deletion or automatic purge |
| Safety | No reporting/blocking UI | Participant blocking stops sends both ways without deleting evidence; report form and institution-scoped review queue |
| Moderation | No dedicated review surface | School administrators or explicitly granted reviewers can access reported conversations and record disposition/notes; access and actions audited |
| Accessibility | Sparse controls | Explicit labels, keyboard focus indicators, status/error announcements, escaped content, timezone-labelled timestamps and responsive layout |

An archived conversation is hidden only for that participant. A block is removable only by its creator. A moderator does not automatically gain access to every unreported conversation. Reporting is a manual review workflow, not an automatic content filter or emergency response service.

Message content, reports and personal contact details are not copied into general audit descriptions. Original messages remain in the restricted conversation record. Notification and reminder failures do not delete stored messages or create fabricated delivery/read claims. “Sent” means stored in PIIE, not received by an email provider.

## Internal scorecard

Engineering assessment: 0 = absent, 1 = unsafe/incomplete, 2 = basic with material gaps, 3 = functional with limitations, 4 = tested against defined requirements, 5 = independently/operationally validated. Scores are not a security certification. New scores apply to local code and synthetic verification, not the currently deployed site.

| Area | Before / 5 | Implemented / 5 | Remaining gap |
|---|---:|---:|---|
| Security and privacy | 1 | 4 | Exact-candidate Linux CI and production acceptance still required |
| Role-based messaging | 0 | 4 | Course-specific lecturer relationships/groups are later scope |
| Mobile usability | 1 | 4 | Pilot with real students and assistive technology |
| Live delivery | 1 | 3 | Polling rather than WebSockets; no background push |
| Notifications | 1 | 3 | SMTP and scheduler acceptance; SMS/push not implemented |
| Contact discovery | 2 | 4 | Large-directory load testing; first 30 matches shown |
| History and archiving | 2 | 3 | Owner must define retention/export policy; no transcript export yet |
| Moderation/reporting | 0 | 3 | Assign reviewers and response expectations; no automated filtering |
| Attachments and course context | 0 | 0 | Groups/course membership and scanned private files not implemented |
| Accessibility and language support | 1 | 2 | English-only new interface; formal accessibility/translation acceptance pending |

Under the proposed “below 3 blocks launch” rule, full benchmark parity is **not launch-ready**. The below-3 areas remain explicit blockers for that scope. A limited direct-messaging pilot is a separate scope decision after release checks; it must not be advertised as having attachments, class groups, automatic translation or SMS/push delivery.

## Remaining phases

1. Course/class groups: derive membership from authoritative course/class assignments; staff create groups and control student posting. Preserve enrolments and do not invent membership for portal-only students.
2. Attachments: private authenticated downloads, course/conversation ownership checks, MIME/size allowlists, malware scanning with quarantine, quotas and a retention rule. Confirm the scanner/runtime before enabling uploads; do not expose unscanned public files.
3. Translation and accessibility: use the existing locale system for interface strings; select a translation provider and privacy rules for message content; retain originals. Complete keyboard/screen-reader and language testing.
4. Operational adoption: reviewer roster and response targets, onboarding instructions, retention/export policy, delivery monitoring, exact SMTP inbox acceptance and load tests. SMS and background push require their own providers and credentials.

## Release and rollback notes

Local verification passed: **124 PHP tests with 3,769 assertions**, **6 JavaScript behavioural tests**, and **9 headless Chrome rendering checks** covering the student inbox, conversation and moderator pages at 375, 768 and 1440 pixels. The rendering checks found no horizontal overflow, missing form labels or JavaScript exceptions. Screenshots and browser results are in `output/messaging-review/` and use only synthetic in-memory fixtures.

The tests cover cross-school/nonparticipant access through every legacy portal; recipient forgery; blank/oversized messages; duplicate submissions; read-state isolation; support-grant scoping and revocation; personal archive/search; blocking; moderator authorisation; mail delays/coalescing/retries; send/poll rate-limit separation; and existing notification, RBAC, student-dashboard and password-activation regressions. JavaScript tests cover escaped incoming content, send confirmation, preserved drafts/retry tokens, incoming/outgoing ordering and access revocation. These do not substitute for exact-candidate Linux CI, production migration rehearsal, a real inbox-delivery check or real-student usability testing.

The additive migration `2026_10_15_050000_add_messaging_safety_and_delivery_tables.php` adds an optional client-message UUID and indexes to `chats`, plus separate preferences, blocks, reports and reminder-outbox tables. It does not rewrite or delete historical messages, combine legacy duplicate threads, or alter academic tables. Existing duplicate threads remain separate historical records; new starts reuse a matching thread.

Before deployment, run exact-candidate CI, review production pending migrations, take the usual verified backups, stage and inspect the migration preview, then activate through the protected workflow. The scheduler must run `schedule:run`; `messages:send-reminders` is scheduled every minute without overlap. Mail remains pending when SMTP is unconfigured and has a failed state after bounded unsuccessful retries. Application responses and emails must use the production HTTPS base URL.

New safety tables are deliberately retained by the migration's `down()` method. Do not drop them as a routine rollback. Reverting to the previous chat code would reintroduce the access-control defects, so a failed release needs a forward fix or restricted messaging access while repairing it; do not restore insecure chat routes merely to make a screen load.

## Separate academic-results integration

No source-system repository, API contract or hosting details were supplied in this task. No marks integration was coded and neither system's database was modified for it. The next results task is an audit of the identified source repository and schema, followed by stable student-ID mapping and server-to-server read-only published-results access. Do not use names alone or expose source database credentials to students.

## Message for the owner

> We have implemented the first secure chat upgrade locally: students can contact authorised staff, search on phones, receive automatic conversation updates and unread notifications, archive history, and report or block inappropriate contact. All portals now share the same school and conversation-access checks. Authorised reviewers have a reported-conversation review screen. The upgrade still needs the normal release process and operational checks. Class groups, scanned attachments, translation and SMS/push delivery remain later phases and are not being claimed as completed.
