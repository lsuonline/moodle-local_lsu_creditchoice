# LSU college credit Choice

Component: `local_lsu_creditchoice` • Version: 0.1.0 (beta)

Creates native Moodle Choice activities for Workday sections matching the supplied
query. All source is included in this installable ZIP. No Moodle core modifications.

## Install and configure

1. Install the ZIP through **Site administration -> Plugins -> Install plugins**, or
   extract `lsu_creditchoice` into `local/` on Moodle 4.5–5.0 or `public/local/`
   on Moodle versions using the public directory layout (including 5.3).
2. Complete Moodle's plugin installation/Notifications page.
3. Enable activity tagging and create a **standard** tag in the collection used
   by course activities under **Site administration -> Appearance -> Manage tags**.
4. Open **Site administration -> Plugins -> Local plugins -> LSU college credit Choice**.
   Review the supplied name, HTML description, Yes/No labels, and select the tag.
5. Enable automation. It is disabled on installation to allow configuration first.
6. Ensure Moodle cron runs every minute. The plugin task runs every 15 minutes;
   it can also be run from **Server -> Tasks -> Scheduled tasks** when Run now is enabled.

The installed Workday integration must supply `enrol_wds_sections`,
`enrol_wds_student_enroll`, and `enrol_wds_periods` with the columns in your query.
The Workday component name is not assumed. Missing tables produce a task error.
Database prefixes are handled by Moodle's `{table}` syntax.

## Selection and activity behaviour

- Section number length > 12; period start < now; period end > now + 10 days;
  at least one matching student-enrolment row with grading scheme exactly
  `LSUAM Choice Credit Modality`; Moodle status is not `Pending`.
- Equivalent `EXISTS` replaces the multiplying enrolment join and `GROUP BY`.
  Results are deduplicated by course ID, so multiple sections produce one Choice.
- `moodle_status` must be a canonical positive decimal Moodle course ID. Text,
  whitespace, leading zeroes, site home, missing courses, missing course end dates,
  and already-expired deadlines are skipped and logged.
- One Choice is created in section 0 (General), visible immediately, with the
  requested default title, description, and two configurable options.
- Single response; updating a response is disabled; no response limits;
  results are never published to students; no groups or automatic completion.
  Labels are plain text, as in the standard Choice form.
- Deadline is `course.enddate + 604800`, using the actual timestamp, not the end
  of that calendar day. Moodle displays it in the user's timezone. It is not
  calculated from the Workday period end date.
- Before finalisation, course end-date changes update the Choice closing time and
  calendar event at the next task run. No end date causes an error; the previous
  closing time is retained. Finalised activities are not reopened automatically.
- Creation settings (including tag/text) affect new activities only. Existing
  activities retain their original wording and tag.

## Automatic No responses

Enabled by default because the supplied description promises this behaviour.
At the first successful run more than 60 seconds after the deadline, the task
records the original second option for unanswered users. The grace period allows
ordinary requests made just before closing to finish; standard Moodle closing
checks still reject new student submissions after the deadline.

The roster is **active Moodle enrolments at processing time**, with capability
`mod/choice:choose`, excluding suspended accounts and anyone who can read Choice
responses (normally instructors/managers). This is not a historical enrolment
snapshot and is not restricted to individual Workday student rows: the supplied
schema/query identifies courses but supplies no Moodle user mapping. Students
whose enrolments have expired before processing are excluded. Confirm that this
roster matches your credit-processing policy before production use.

Existing responses are never intentionally overwritten. Defaults use the native
Choice response API, so Choice reporting, events and privacy export work normally.
Cron is the actor for automated events; the student is the related user. The task
logs default counts. This plugin does not send results to Workday, modify grades,
or update transcripts. Moodle stores a default as an ordinary No response; it
is distinguishable via event actor/task logs, not a separate flag in Choice reports.

Processing is once per activity, transactional and retryable. Newly enrolled users
after finalisation are not defaulted. Disabling automatic defaults leaves unanswered
responses empty; update the description if you do this. Disabling the plugin stops
both creation and finalisation but native activity closing times still apply.

## Safeguards and administration

- Plugin lock plus a unique per-course tracking key prevent duplicate task creation.
- Activities are created using `add_moduleinfo()`, not hand-inserted module records.
- Each creation/default batch uses a delegated transaction with explicit rollback.
- Created activities remain tracked after the Workday selection window has passed.
- The original option IDs and a hash of both option texts are retained. Changed
  options, multiple responses, response limits, response updating, or submit-based
  completion block automatic defaults and produce a task error for review.
- Student immutability is standard Choice behaviour, not protection against an
  administrator or teacher who can edit/delete responses or change activity settings.
  Restrict these capabilities separately if policy requires it.
- Deleting a managed activity leaves a creation marker: it is logged and not
  recreated automatically. This is intentional protection against repeat creation.
- Reserved course-module ID number: `local_lsu_creditchoice`. A copied/restored
  Choice carrying this marker is not silently adopted and prevents new creation
  in that course pending administrator review. Plugin tracking is site-local and
  is not included in course backups. Review restored activities explicitly.
- Uninstalling removes plugin configuration/tracking, not native Choices or answers.
  Reinstallation sees existing reserved markers and requires administrator review.
- No public endpoints, custom web forms, or custom capabilities are introduced.
  Only site configuration administrators can change plugin settings.
- Plugin storage contains course/activity/option references, timestamps and a hash,
  not student identifiers. Choice owns response privacy export/deletion. Its
  metadata relationship is declared in the plugin privacy provider.

## Compatibility and validation status

Targets Moodle 4.5–5.3 using the common native Choice/module/tag/lock APIs. Uses
`$CFG->dirroot` so the public-directory move is handled by Moodle. Source reviewed
against `MOODLE_405_STABLE` and `main/public` Choice/module implementations.

The source uses syntax available in PHP 8.3 and no intentionally version-specific
8.4–8.6 features. This is **not certification of PHP 8.5 or 8.6**, or of unsupported
Moodle/PHP pairings. Moodle 4.5 documents PHP 8.3 support; Moodle 5.3 documents PHP
8.3 and 8.4. Use a PHP version supported by the installed Moodle core.

Completed here: all PHP files parsed using php-parser in PHP 8.3 mode; XML parsed;
ZIP structure/integrity and source whitespace checked. The environment has no PHP
runtime, Moodle database, PHPUnit runner, or Moodle PHPCS installation. Therefore
PHP lint, Moodle code checker, PHPUnit and live 4.5/5.3 acceptance tests have **not**
been run. The plugin is marked beta rather than claiming unverified compliance.

Included `tests/manager_test.php` covers creation/settings, idempotency, default No
while preserving Yes, instructor exclusion, finalisation idempotency, missing end
dates, and changed-option blocking. In a configured Moodle development checkout,
run the corresponding commands with that checkout's PHP and PHPUnit setup:

```sh
vendor/bin/phpunit --testsuite local_lsu_creditchoice_testsuite
vendor/bin/phpcs --standard=moodle local/lsu_creditchoice
```

For the public layout, point PHPCS at `public/local/lsu_creditchoice`. PHPUnit
configuration and vendor location depend on the Moodle checkout. Run its normal
PHPUnit initialisation first. Use a dedicated test database, never production.

Before production, verify the selection on representative Workday data, including
exact time boundaries, duplicate sections, invalid statuses and missing courses.
On staging, confirm that a student can submit once, cannot change or submit after
closing, that course-end edits update the calendar event, and that cron processes
managed courses after they fall out of the ten-day query window. Verify automatic
No recipient eligibility with your actual enrolment lifetimes and role overrides.

## References

- https://moodledev.io/general/releases/4.5
- https://moodledev.io/general/releases/5.3
- https://moodledev.io/general/development/policies/php
- https://github.com/moodle/moodle/blob/MOODLE_405_STABLE/course/modlib.php
- https://github.com/moodle/moodle/blob/MOODLE_405_STABLE/mod/choice/lib.php
- https://github.com/moodle/moodle/blob/main/public/mod/choice/lib.php

Copyright 2026 Robert Russo & Louisiana State University. GNU GPL v3 or later.
