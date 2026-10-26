<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <http://www.gnu.org/licenses/>.

/**
 * Manage the lifecycle of college credit Choices.
 *
 * @package    local_lsu_creditchoice
 * @copyright  2026 Louisiana State University
 * @copyright  2026 Robert Russo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_lsu_creditchoice;

/**
 * Uses core activity APIs and retains a durable per-course creation marker.
 *
 * @copyright 2026 Louisiana State University
 * @copyright 2026 Robert Russo
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class manager {
    /** @var string Reserved course-module ID number. */
    public const IDNUMBER = 'local_lsu_creditchoice';

    /** Run synchronisation. Caller must hold the plugin synchronisation lock. */
    public function execute(): void {
        global $DB;
        $this->load_libraries();
        $failed = false;

        // Managed courses must still be processed after the Workday query stops matching.
        $records = $DB->get_recordset('local_lsu_creditchoice', ['finalized' => 0], 'id');
        try {
            foreach ($records as $record) {
                try {
                    $this->process_existing($record);
                } catch (\Throwable $e) {
                    $failed = true;
                    mtrace('Credit Choice course ' . $record->courseid . ': ' . $e->getMessage());
                }
            }
        } finally {
            $records->close();
        }
        $config = $this->get_settings();
        $coursegroups = $this->get_eligible_course_groups(time());
        foreach ($coursegroups as $courseid => $groupids) {
            try {
                $this->create_for_course($courseid, $config, $groupids);
            } catch (\Throwable $e) {
                $failed = true;
                mtrace('Credit Choice course ' . $courseid . ': ' . $e->getMessage());
            }
        }
        if ($failed) {
            throw new \moodle_exception('processingfailed', 'local_lsu_creditchoice');
        }
    }

    /** Load libraries using Moodle's resolved web root on both directory layouts. */
    protected function load_libraries(): void {
        global $CFG;
        require_once($CFG->dirroot . '/course/format/lib.php');
        require_once($CFG->dirroot . '/course/modlib.php');
        require_once($CFG->dirroot . '/mod/choice/lib.php');
        require_once($CFG->dirroot . '/mod/choice/locallib.php');
        require_once($CFG->libdir . '/enrollib.php');
    }

    /**
     * Validate settings before creation; existing deadlines do not depend on settings validity.
     *
     * @return \stdClass Validated settings with a standard tag name.
     */
    public function get_settings(): \stdClass {
        global $DB;
        $config = get_config('local_lsu_creditchoice');
        $collection = \core_tag_area::get_collection('core', 'course_modules');
        $tag = $DB->get_record('tag', [
            'id' => (int)($config->tagid ?? 0), 'tagcollid' => $collection, 'isstandard' => 1,
        ]);
        if (!$tag || !\core_tag_tag::is_enabled('core', 'course_modules')) {
            throw new \moodle_exception('invalidconfig', 'local_lsu_creditchoice');
        }
        foreach (['choicename', 'description', 'yeslabel', 'nolabel'] as $field) {
            if (trim($config->$field ?? '') === '') {
                throw new \moodle_exception('invalidconfig', 'local_lsu_creditchoice');
            }
        }
        if ($config->yeslabel === $config->nolabel || \core_text::strlen($config->choicename) > 255) {
            throw new \moodle_exception('invalidconfig', 'local_lsu_creditchoice');
        }
        $config->tagname = $tag->rawname;
        return $config;
    }

    /**
     * List distinct academic period IDs available from the Workday integration.
     *
     * Missing tables must not prevent an administrator from opening the settings page.
     *
     * @return array Academic period IDs indexed by the same IDs.
     */
    public static function get_academic_periods(): array {
        global $DB;
        if (!$DB->get_manager()->table_exists(new \xmldb_table('enrol_wds_periods'))) {
            return [];
        }
        $ids = $DB->get_fieldset_sql(
            'SELECT DISTINCT academic_period_id
               FROM {enrol_wds_periods}
              WHERE academic_period_id IS NOT NULL
           ORDER BY academic_period_id'
        );
        $periods = [];
        foreach ($ids as $id) {
            if ((string)$id === '') {
                continue;
            }
            $periods[(string)$id] = (string)$id;
        }
        return $periods;
    }

    /**
     * Validate a nonnegative whole-number cutoff without risking timestamp overflow.
     *
     * @param mixed $value Configured cutoff in days.
     * @return int|false Validated days, or false for an invalid setting.
     */
    public static function validate_cutoff_days($value): int|false {
        return filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0, 'max_range' => intdiv(PHP_INT_MAX, DAYSECS) - 1],
        ]);
    }

    /**
     * Find unique valid course IDs and their matching Moodle group IDs for the configured period and cutoff.
     *
     * @param int $now Comparison timestamp.
     * @return array Map of course IDs to arrays of group IDs.
     */
    public function get_eligible_course_groups(int $now): array {
        global $DB;
        foreach (['enrol_wds_sections', 'enrol_wds_student_enroll', 'enrol_wds_periods', 'enrol_wds_courses'] as $table) {
            if (!$DB->get_manager()->table_exists(new \xmldb_table($table))) {
                throw new \moodle_exception('missingtable', 'local_lsu_creditchoice', '', $table);
            }
        }

        $academicperiodid = get_config('local_lsu_creditchoice', 'academicperiodid');
        if ($academicperiodid === false || (string)$academicperiodid === '' ||
                !$DB->record_exists('enrol_wds_periods', ['academic_period_id' => $academicperiodid])) {
            throw new \moodle_exception('invalidacademicperiod', 'local_lsu_creditchoice');
        }
        $configuredcutoff = get_config('local_lsu_creditchoice', 'cutoffdays');
        $cutoffdays = self::validate_cutoff_days($configuredcutoff === false ? 10 : $configuredcutoff);
        if ($cutoffdays === false || $now > PHP_INT_MAX - $cutoffdays * DAYSECS) {
            throw new \moodle_exception('invalidcutoffdays', 'local_lsu_creditchoice');
        }

        $groupconcat = $DB->sql_concat('sec.course_subject_abbreviation', "' '", 'cou.course_number', "' '", 'sec.section_number');
        $sql = "SELECT sec.id AS sectionid, sec.moodle_status, g.id AS groupid, g.courseid AS groupcourseid
                  FROM {enrol_wds_sections} sec
                  JOIN {enrol_wds_periods} per ON per.academic_period_id = sec.academic_period_id
                  JOIN {enrol_wds_courses} cou ON cou.course_listing_id = sec.course_listing_id
             LEFT JOIN {groups} g ON g.courseid = sec.moodle_status AND g.name = $groupconcat
                 WHERE per.academic_period_id = :academicperiodid
                   AND per.end_date > :cutoff
                   AND sec.moodle_status <> :pending
                   AND EXISTS (
                       SELECT 1 FROM {enrol_wds_student_enroll} se
                        WHERE se.section_listing_id = sec.section_listing_id
                          AND se.grading_scheme = :scheme
                   )";
        $records = $DB->get_recordset_sql($sql, [
            'academicperiodid' => $academicperiodid,
            'cutoff' => $now + $cutoffdays * DAYSECS,
            'pending' => 'Pending',
            'scheme' => 'LSUAM Choice Credit Modality',
        ]);

        $allcourses = [];
        $unmatchedcourses = [];
        try {
            foreach ($records as $record) {
                $value = (string)$record->moodle_status;
                if (!ctype_digit($value) || (int)$value <= SITEID || (string)(int)$value !== $value) {
                    mtrace('Credit Choice: skipped noncanonical course ID on section ' . $record->sectionid);
                    continue;
                }
                $courseid = (int)$value;
                if (!isset($allcourses[$courseid])) {
                    $allcourses[$courseid] = [];
                }
                if ($record->groupid !== null && (int)$record->groupcourseid === $courseid) {
                    $groupid = (int)$record->groupid;
                    $allcourses[$courseid][$groupid] = $groupid;
                } else {
                    $unmatchedcourses[$courseid] = true;
                }
            }
        } finally {
            $records->close();
        }

        $result = [];
        ksort($allcourses);
        foreach ($allcourses as $courseid => $groups) {
            if (!empty($unmatchedcourses[$courseid]) || empty($groups)) {
                mtrace('Credit Choice: skipped course ' . $courseid . ' because one or more sections had no matching Moodle groups.');
            } else {
                ksort($groups);
                $result[$courseid] = array_values($groups);
            }
        }
        return $result;
    }

    /**
     * Get matching group IDs for a given course ID based on section matching.
     *
     * @param int $courseid Course ID.
     * @return int[] Group IDs.
     */
    public function get_course_groups(int $courseid): array {
        global $DB;
        foreach (['enrol_wds_sections', 'enrol_wds_student_enroll', 'enrol_wds_courses', 'enrol_wds_periods'] as $table) {
            if (!$DB->get_manager()->table_exists(new \xmldb_table($table))) {
                return [];
            }
        }

        $academicperiodid = get_config('local_lsu_creditchoice', 'academicperiodid');
        if ($academicperiodid === false || (string)$academicperiodid === '') {
            return [];
        }

        $groupconcat = $DB->sql_concat('sec.course_subject_abbreviation', "' '", 'cou.course_number', "' '", 'sec.section_number');
        $sql = "SELECT sec.id AS sectionid, g.id AS groupid, g.courseid AS groupcourseid
                  FROM {enrol_wds_sections} sec
                  JOIN {enrol_wds_periods} per ON per.academic_period_id = sec.academic_period_id
                  JOIN {enrol_wds_courses} cou ON cou.course_listing_id = sec.course_listing_id
             LEFT JOIN {groups} g ON g.courseid = :courseid AND g.name = $groupconcat
                 WHERE sec.moodle_status = :moodlestatus
                   AND per.academic_period_id = :academicperiodid
                   AND sec.moodle_status <> :pending
                   AND EXISTS (
                       SELECT 1 FROM {enrol_wds_student_enroll} se
                        WHERE se.section_listing_id = sec.section_listing_id
                          AND se.grading_scheme = :scheme
                   )";
        $records = $DB->get_recordset_sql($sql, [
            'courseid' => $courseid,
            'moodlestatus' => (string)$courseid,
            'academicperiodid' => $academicperiodid,
            'pending' => 'Pending',
            'scheme' => 'LSUAM Choice Credit Modality',
        ]);
        $groupids = [];
        $unmatched = false;
        try {
            foreach ($records as $record) {
                if ($record->groupid !== null && (int)$record->groupcourseid === $courseid) {
                    $gid = (int)$record->groupid;
                    $groupids[$gid] = $gid;
                } else {
                    $unmatched = true;
                }
            }
        } finally {
            $records->close();
        }
        if ($unmatched || empty($groupids)) {
            mtrace('Credit Choice: skipped course ' . $courseid . ' because one or more sections had no matching Moodle groups.');
            return [];
        }
        ksort($groupids);
        return array_values($groupids);
    }

    /**
     * Find unique valid course IDs for the configured period and period-end cutoff.
     *
     * @param int $now Comparison timestamp.
     * @return int[] Course IDs.
     */
    public function eligible_course_ids(int $now): array {
        return array_keys($this->get_eligible_course_groups($now));
    }

    /**
     * Create one activity atomically. Caller must hold the plugin lock.
     *
     * @param int $courseid Course ID.
     * @param \stdClass $config Validated plugin configuration.
     * @return int Course-module ID, or zero when skipped.
     */
    public function create_for_course(int $courseid, \stdClass $config, array $groupids = []): int {
        global $DB;
        $this->load_libraries();
        if ($DB->record_exists('local_lsu_creditchoice', ['courseid' => $courseid])) {
            return 0;
        }
        $course = $DB->get_record('course', ['id' => $courseid]);
        if (!$course || $courseid == SITEID || !$course->enddate || $course->enddate + 7 * DAYSECS <= time()) {
            mtrace('Credit Choice: skipped missing course, unset end date, or expired deadline: ' . $courseid);
            return 0;
        }

        if (empty($groupids)) {
            $groupids = $this->get_course_groups($courseid);
        }
        if (empty($groupids)) {
            mtrace('Credit Choice: skipped course ' . $courseid . ' because no matching Moodle groups were found.');
            return 0;
        }

        // A restored/copied activity with our marker requires human review, never silent adoption.
        if ($DB->record_exists('course_modules', ['course' => $courseid, 'idnumber' => self::IDNUMBER])) {
            throw new \moodle_exception('existingmarker', 'local_lsu_creditchoice');
        }

        $conditions = [];
        foreach ($groupids as $gid) {
            $conditions[] = ['type' => 'group', 'id' => (int)$gid];
        }
        $availability = json_encode([
            'op' => '|',
            'c' => $conditions,
            'show' => false,
            'showc' => array_fill(0, count($conditions), false),
        ]);

        $transaction = $DB->start_delegated_transaction();
        try {
            $module = (object)[
                'modulename' => 'choice',
                'module' => $DB->get_field('modules', 'id', ['name' => 'choice'], MUST_EXIST),
                'section' => 0,
                'visible' => 1,
                'showdescription' => 1,
                'cmidnumber' => self::IDNUMBER,
                'name' => $config->choicename,
                'intro' => $config->description,
                'introformat' => FORMAT_HTML,
                'option' => [$config->yeslabel, $config->nolabel],
                'limit' => [0, 0],
                'limitanswers' => 0,
                'allowupdate' => 1,
                'allowmultiple' => 0,
                'showresults' => 0,
                'publish' => 0,
                'showunanswered' => 1,
                'includeinactive' => 0,
                'display' => 1,
                'timeopen' => 0,
                'timeclose' => (int)$course->enddate + 7 * DAYSECS,
                'showpreview' => 0,
                'completion' => 0,
                'completionsubmit' => 0,
                'groupmode' => 0,
                'groupingid' => 0,
                'availability' => $availability,
                'availabilityconditionsjson' => $availability,
                'tags' => [$config->tagname],
            ];
            $module = add_moduleinfo($module, $course);
            $options = array_values($DB->get_records('choice_options', ['choiceid' => $module->instance], 'id'));
            $DB->insert_record('local_lsu_creditchoice', (object)[
                'courseid' => $courseid, 'cmid' => $module->coursemodule, 'choiceid' => $module->instance,
                'nooptionid' => $options[1]->id,
                'optionshash' => hash('sha256', serialize([$options[0]->text, $options[1]->text])),
                'timecreated' => time(), 'finalized' => 0,
            ]);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }
        mtrace('Credit Choice created in course ' . $courseid . ', cmid ' . $module->coursemodule);
        return (int)$module->coursemodule;
    }

    /**
     * Synchronise the deadline, then default unanswered responses once closed.
     *
     * @param \stdClass $record Managed activity record.
     */
    public function process_existing(\stdClass $record): void {
        global $DB;
        $this->load_libraries();
        if ($record->finalized) {
            return;
        }
        $course = $DB->get_record('course', ['id' => $record->courseid]);
        $cm = get_coursemodule_from_id('choice', $record->cmid, $record->courseid, false, IGNORE_MISSING);
        if (!$course || !$cm || $cm->deletioninprogress || (int)$cm->instance !== (int)$record->choiceid) {
            mtrace('Credit Choice missing/deleting: course ' . $record->courseid . '; not recreating.');
            return;
        }
        if (!$course->enddate) {
            throw new \moodle_exception('missingenddate', 'local_lsu_creditchoice');
        }
        $choice = choice_get_choice($record->choiceid);
        if (!$choice) {
            throw new \moodle_exception('changedchoice', 'local_lsu_creditchoice');
        }
        $deadline = (int)$course->enddate + 7 * DAYSECS;
        if ((int)$choice->timeclose !== $deadline) {
            $choice->timeclose = $deadline;
            $DB->set_field('choice', 'timeclose', $deadline, ['id' => $choice->id]);
            choice_set_events($choice);
            rebuild_course_cache($course->id, true);
        }

        $groupids = $this->get_course_groups((int)$record->courseid);
        if (!empty($groupids)) {
            $conditions = [];
            foreach ($groupids as $gid) {
                $conditions[] = ['type' => 'group', 'id' => (int)$gid];
            }
            $targetavailability = json_encode([
                'op' => '|',
                'c' => $conditions,
                'show' => false,
                'showc' => array_fill(0, count($conditions), false),
            ]);
            if ($cm->availability !== $targetavailability) {
                $DB->set_field('course_modules', 'availability', $targetavailability, ['id' => $cm->id]);
                rebuild_course_cache($course->id, true);
            }
        }

        // Grace period lets requests submitted immediately before closure finish.
        if (time() <= $deadline + MINSECS || !get_config('local_lsu_creditchoice', 'autodefault')) {
            return;
        }
        if ($choice->allowupdate || $choice->allowmultiple || $choice->limitanswers ||
                $choice->completionsubmit ||
                hash('sha256', serialize(array_values($choice->option))) !== $record->optionshash ||
                !isset($choice->option[$record->nooptionid]) || count($choice->option) !== 2) {
            throw new \moodle_exception('changedchoice', 'local_lsu_creditchoice');
        }
        $context = \context_module::instance($cm->id);
        $users = get_enrolled_users($context, 'mod/choice:choose', 0, 'u.id,u.suspended', 'u.id', 0, 0, true);
        $transaction = $DB->start_delegated_transaction();
        try {
            $count = 0;
            foreach ($users as $user) {

                // Exclude instructors/managers; never replace a submitted response.
                if ($user->suspended || has_capability('mod/choice:readresponses', $context, $user->id) ||
                        $DB->record_exists('choice_answers', ['choiceid' => $choice->id, 'userid' => $user->id])) {
                    continue;
                }
                choice_user_submit_response((int)$record->nooptionid, $choice, (int)$user->id, $course, $cm);
                $count++;
            }
            $DB->set_field('local_lsu_creditchoice', 'finalized', time(), ['id' => $record->id]);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }
        mtrace('Credit Choice course ' . $course->id . ': recorded ' . $count . ' automatic No responses.');
    }
}
