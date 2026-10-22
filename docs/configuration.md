# Academic period and eligibility cutoff

These settings are under **Site administration -> Plugins -> Local plugins ->
LSU college credit Choice**.

| Setting | Default | Behaviour |
| --- | --- | --- |
| Academic period (`academicperiodid`) | Not selected | Required for new Choice creation. Select an existing `academic_period_id` from `enrol_wds_periods`. |
| Period-end cutoff, days (`cutoffdays`) | `10` | Require `per.end_date > now + cutoffdays * 86400`. Nonnegative whole days only. |

The academic period list contains distinct, nonempty IDs, sorted by ID. The list
is read from the Workday table when the settings page is loaded, so newly imported
periods become available without editing plugin code. Refresh the settings page
after importing periods. IDs are preserved as values, including nonnumeric IDs.

The plugin no longer uses `per.start_date < now` to discover the academic period.
A manually selected future-start period can match if its end date passes the cutoff.
An expired period remains available in the dropdown, but cannot pass a nonnegative
cutoff. If Workday's periods table is missing, the settings page still opens with
only the selection prompt; the scheduled task reports the missing required table.

An empty selection, or one whose ID was removed from the periods table, raises a
configuration error before running the eligibility query. There is no fallback
to all periods or to date-based selection. Invalid cutoff values are rejected
on the settings page and checked again at runtime. If no cutoff has been stored
yet, the task uses ten days.

## Cutoff examples

The comparison is strict: equality does not qualify. Each day is exactly 86,400
seconds, rather than a timezone-dependent calendar day.

| Cutoff | Requirement for the selected academic period |
| --- | --- |
| `10` | The end timestamp must be more than ten days after the task's comparison time. |
| `3` | The end timestamp must be more than three days after the task's comparison time. |
| `0` | The end timestamp must be strictly in the future. The end-date check still applies. |

The cutoff controls whether a **new Choice is created**. The response deadline
remains `course.enddate + 7 * 86400`, using the Moodle course end timestamp.
Selecting a different period or changing the cutoff does not modify existing
Choice text, response options, answers, or tags. Existing managed activities are
processed before new course selection, including Choices from earlier periods.

## Eligibility query

The production query uses Moodle's database abstraction for character length and
table prefixes. Its MySQL-equivalent selection is:

```sql
SELECT sec.id, sec.moodle_status
  FROM {enrol_wds_sections} sec
  JOIN {enrol_wds_periods} per
    ON per.academic_period_id = sec.academic_period_id
 WHERE CHAR_LENGTH(sec.section_number) > :minlength
   AND per.academic_period_id = :academicperiodid
   AND per.end_date > :cutoff
   AND sec.moodle_status <> :pending
   AND EXISTS (
       SELECT 1
         FROM {enrol_wds_student_enroll} se
        WHERE se.section_listing_id = sec.section_listing_id
          AND se.grading_scheme = :scheme
   )
```

Parameters are bound values:

| Parameter | Value |
| --- | --- |
| `minlength` | `12` |
| `academicperiodid` | Configured academic period ID |
| `cutoff` | Comparison timestamp plus configured cutoff days multiplied by `86400` |
| `pending` | `Pending` |
| `scheme` | `LSUAM Choice Credit Modality` |

The PHP layer validates canonical numeric Moodle course IDs and deduplicates them.
Creation then checks that each course exists, has an end date, has an unexpired
response deadline, and has not already received a managed Choice.

## Updating an existing installation

1. Replace the plugin files and visit Moodle's Notifications page for version
   `2026102200` (release `0.3.0`). No schema migration is needed; there is no
   `db/upgrade.php` in this release.
2. Select the intended academic period in the plugin settings. Existing
   installations have no automatic selection for this new setting.
3. Review the ten-day default or enter a different nonnegative whole number.
4. Save settings and run the scheduled task, or allow its normal schedule to run.

Existing managed Choice processing remains active before eligibility validation
when automation is enabled, even if the new academic period setting is still
missing. New creation reports a configuration error until selection is complete.

## Validation

Completed for this release:

- Native PHP 8.3 lint passed for all ten PHP files.
- Twenty-seven focused checks passed while executing the actual plugin manager
  against an in-memory SQLite database adapter, including period switching,
  future-start periods, duplicate rows, cutoff boundaries, zero/default cutoffs,
  invalid settings, timestamp overflow, and numeric period IDs.
- Source whitespace, XML parsing, and installable ZIP structure/integrity checks
  passed. `db/install.xml` is unchanged and no `upgrade.php` is included.

This release includes regression coverage for explicit period selection,
future-start periods, sorted/distinct dropdown values, duplicate sections and
enrolments, configurable cutoff boundaries, zero cutoff, unsaved default, and
invalid period/cutoff settings.

The new `selection_test` tests create temporary fixture tables only in Moodle's
dedicated test database and drop them afterwards. They skip when any Workday
fixture table already exists; use a clean Moodle test installation without the
Workday integration to run these fixtures. The original activity tests remain
available in `manager_test`.

Live Moodle PHPUnit tests and Moodle PHPCS have not been run in the build
environment. Run the suite and Moodle code checker in a configured Moodle
development checkout, and verify the actual Workday selection on staging before
deploying to production.
