# Change log

## 0.3.0 — 2026-10-22

- Add an administrator-selected academic period, populated from distinct,
  nonempty `academic_period_id` values in `enrol_wds_periods`.
- Replace automatic period selection using `per.start_date < now` with the
  configured academic period ID. No academic period is selected by default.
- Add a validated period-end cutoff in whole days, defaulting to ten days.
  Zero requires the period to end strictly after the task's comparison time.
- Validate the period selection and cutoff before scanning for new courses.
  Existing managed Choice processing remains independent of these settings.
- Update administrator language strings, README, configuration documentation,
  and regression tests.
- Bump plugin version to `2026102200`. No schema changes and no `db/upgrade.php`.

## 0.2.0-Alphabetized_Aardvark

- Repository baseline used for this update (commit `92deb0a`).
