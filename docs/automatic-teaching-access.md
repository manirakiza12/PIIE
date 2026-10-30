# Automatic teaching access

Assign a teacher to each Course Allocation they teach. An eligible assignment on an open or in-progress course activates immediately. Course content, assignment authoring and grading, attendance, and the course workspace already derive access from this assignment; separate per-feature permission grants are unnecessary. Primary and Co-Lecturers can also schedule and host live classes.

The scheduler runs `academic:activate-teaching-allocations` every minute using the existing scheduler cron. It handles eligible existing planned assignments, replacements, and assignments made while a course was still draft. It reuses the same activation validation, conflict checks, locks, and audit trail as manual activation. A planned assignment is treated as an administrator's teaching appointment to activate when eligible.

Preview without changing records:

```sh
php artisan academic:activate-teaching-allocations --school=1 --dry-run
```

Run for an institution immediately:

```sh
php artisan academic:activate-teaching-allocations --school=1
```

No migration or permanent user permission grants are added. Access remains scoped to the assigned course and institution, assignment role, effective dates, account status, and course lifecycle. Future appointments do not grant early teaching access. Expired assignments and staff on leave remain deferred; cancelled or ended assignments are never reactivated. Ending or cancelling an assignment removes its operational access through the existing authorization checks.

Live Classes now accepts an eligible allocated teacher without requiring an additional legacy menu grant. Meet Now lists only currently authorized courses, checks authorization before any meeting-provider request, and derives its academic fields from the Course Allocation. Google Meet requires the teacher's own connected Google account; an institution account is not silently substituted for an unconnected lecturer.

Google Cloud consent, OAuth audience/verification, and Calendar API setup remain external prerequisites. Allocation automation cannot authorize Google account access on a teacher's behalf.

Rollout: merge and deploy through the normal protected release workflow, verify the scheduler is running, preview the institution's planned assignments, and inspect activation audit records after the first scheduled pass. The automated command is safe to repeat and does not create duplicate assignments.
