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
 * Tests for academic period selection and the configurable eligibility cutoff.
 *
 * @package    local_lsu_creditchoice
 * @copyright  2026 Louisiana State University
 * @copyright  2026 Robert Russo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_lsu_creditchoice;

/**
 * Test the real selection query using minimal Workday fixtures in a test database.
 *
 * @covers \local_lsu_creditchoice\manager
 * @copyright 2026 Louisiana State University
 * @copyright 2026 Robert Russo
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class selection_test extends \advanced_testcase {
    /** @var string[] Tables created by this test only. */
    private array $createdtables = [];

    /** @var int Section-listing suffix. */
    private int $sectioncount = 0;

    /** Create fixture tables only when no real Workday tables are installed. */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest(true);
        $this->preventResetByRollback();
        $tables = [
            'enrol_wds_periods' => ['academic_period_id', 'start_date', 'end_date'],
            'enrol_wds_courses' => ['course_listing_id', 'course_number'],
            'enrol_wds_sections' => ['academic_period_id', 'course_listing_id', 'course_subject_abbreviation', 'moodle_status', 'section_number', 'section_listing_id'],
            'enrol_wds_student_enroll' => ['section_listing_id', 'grading_scheme'],
        ];
        $dbman = $DB->get_manager();
        foreach (array_keys($tables) as $name) {
            if ($dbman->table_exists(new \xmldb_table($name))) {
                $this->markTestSkipped('Selection fixtures require a test site without existing Workday tables.');
            }
        }
        foreach ($tables as $name => $fields) {
            $table = new \xmldb_table($name);
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            foreach ($fields as $field) {
                if (in_array($field, ['start_date', 'end_date'])) {
                    $table->add_field($field, XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, 0);
                } else {
                    $table->add_field($field, XMLDB_TYPE_CHAR, '255');
                }
            }
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $dbman->create_table($table);
            $this->createdtables[] = $name;
        }
        set_config('academicperiodid', 'PERIOD_A', 'local_lsu_creditchoice');
        set_config('cutoffdays', 10, 'local_lsu_creditchoice');
    }

    /** Remove only the tables owned by this test. */
    protected function tearDown(): void {
        global $DB;
        try {
            foreach (array_reverse($this->createdtables) as $name) {
                $DB->get_manager()->drop_table(new \xmldb_table($name));
            }
        } finally {
            parent::tearDown();
        }
    }

    /**
     * Insert one Workday period.
     *
     * @param string|null $id Academic period ID.
     * @param int $start Start timestamp.
     * @param int $end End timestamp.
     */
    private function add_period(?string $id, int $start, int $end): void {
        global $DB;
        $DB->insert_record('enrol_wds_periods', (object)[
            'academic_period_id' => $id, 'start_date' => $start, 'end_date' => $end,
        ]);
    }

    /**
     * Insert a section with one matching student-enrolment row.
     *
     * @param string $period Academic period ID.
     * @param string $course Moodle status/course ID.
     * @param string $number Section number.
     * @param string $scheme Grading scheme.
     * @return string Section listing ID.
     */
    private function add_section(
        string $period,
        string $course,
        string $number = '1234567890123',
        string $scheme = 'LSUAM Choice Credit Modality',
        string $subject = 'BIOL',
        string $coursenum = '1001',
        string $listingid = 'course_listing_1'
    ): string {
        global $DB;
        $listing = 'listing_' . ++$this->sectioncount;
        if (!$DB->record_exists('enrol_wds_courses', ['course_listing_id' => $listingid])) {
            $DB->insert_record('enrol_wds_courses', (object)[
                'course_listing_id' => $listingid, 'course_number' => $coursenum,
            ]);
        }
        $DB->insert_record('enrol_wds_sections', (object)[
            'academic_period_id' => $period, 'course_listing_id' => $listingid,
            'course_subject_abbreviation' => $subject, 'moodle_status' => $course,
            'section_number' => $number, 'section_listing_id' => $listing,
        ]);
        $DB->insert_record('enrol_wds_student_enroll', (object)[
            'section_listing_id' => $listing, 'grading_scheme' => $scheme,
        ]);
        return $listing;
    }

    /** List distinct, nonempty IDs in order, without filtering by date. */
    public function test_period_choices(): void {
        $this->add_period('PERIOD_B', 0, 1);
        $this->add_period('PERIOD_A', 0, 1);
        $this->add_period('PERIOD_A', 0, 1);
        $this->add_period('', 0, 1);
        $this->add_period(null, 0, 1);
        $this->assertSame([
            'PERIOD_A' => 'PERIOD_A', 'PERIOD_B' => 'PERIOD_B',
        ], manager::get_academic_periods());
    }

    /** Only the configured period is eligible; its future start is not a restriction. */
    public function test_explicit_period_replaces_start_date_filter(): void {
        global $DB;
        $now = 1800000000;
        $this->add_period('PERIOD_A', $now + DAYSECS, $now + 30 * DAYSECS);
        $this->add_period('PERIOD_B', $now - DAYSECS, $now + 30 * DAYSECS);

        // Create Moodle groups matching section names.
        $g1 = $this->getDataGenerator()->create_group(['courseid' => 201, 'name' => 'BIOL 1001 1234567890123']);
        $g2 = $this->getDataGenerator()->create_group(['courseid' => 202, 'name' => 'BIOL 1001 1234567890123']);

        $listing = $this->add_section('PERIOD_A', '201');
        $this->add_section('PERIOD_A', '201');
        $this->add_section('PERIOD_B', '202');
        $this->add_section('PERIOD_A', '203', '123456789012');
        $this->add_section('PERIOD_A', '204', '1234567890123', 'Other grading scheme');
        $this->add_section('PERIOD_A', 'Pending');
        $DB->insert_record('enrol_wds_student_enroll', (object)[
            'section_listing_id' => $listing, 'grading_scheme' => 'LSUAM Choice Credit Modality',
        ]);
        $this->assertSame([201], (new manager())->eligible_course_ids($now));
        $this->assertSame([201 => [$g1->id]], (new manager())->get_eligible_course_groups($now));
        set_config('academicperiodid', 'PERIOD_B', 'local_lsu_creditchoice');
        $this->assertSame([202], (new manager())->eligible_course_ids($now));
        $this->assertSame([202 => [$g2->id]], (new manager())->get_eligible_course_groups($now));
    }

    /** Courses without matching Moodle groups are omitted from eligible course groups. */
    public function test_course_without_group_is_skipped(): void {
        $now = 1800000000;
        $this->add_period('PERIOD_A', $now, $now + 30 * DAYSECS);
        $this->add_section('PERIOD_A', '301', '1234567890123');
        // Course 301 has no group created in Moodle.
        $this->assertSame([], (new manager())->get_eligible_course_groups($now));
        $this->assertSame([], (new manager())->eligible_course_ids($now));
    }

    /** Duplicate group names across different courses do not trigger false unmatched flags. */
    public function test_duplicate_group_names_across_courses(): void {
        $now = 1800000000;
        $this->add_period('PERIOD_A', $now, $now + 30 * DAYSECS);
        $g1 = $this->getDataGenerator()->create_group(['courseid' => 401, 'name' => 'BIOL 1001 1234567890123']);
        $g2 = $this->getDataGenerator()->create_group(['courseid' => 402, 'name' => 'BIOL 1001 1234567890123']);
        $this->add_section('PERIOD_A', '401', '1234567890123');
        $this->add_section('PERIOD_A', '402', '1234567890123');
        $expected = [
            401 => [$g1->id],
            402 => [$g2->id],
        ];
        $this->assertSame($expected, (new manager())->get_eligible_course_groups($now));
    }

    /** get_course_groups filters sections by configured academic period. */
    public function test_get_course_groups_filters_by_period(): void {
        $now = 1800000000;
        $this->add_period('PERIOD_A', $now, $now + 30 * DAYSECS);
        $this->add_period('PERIOD_B', $now, $now + 30 * DAYSECS);
        $g1 = $this->getDataGenerator()->create_group(['courseid' => 501, 'name' => 'BIOL 1001 1234567890123']);
        $this->add_section('PERIOD_A', '501', '1234567890123');
        // Unmatched section in PERIOD_B for course 501.
        $this->add_section('PERIOD_B', '501', '1234567890999');

        set_config('academicperiodid', 'PERIOD_A', 'local_lsu_creditchoice');
        $this->assertSame([$g1->id], (new manager())->get_course_groups(501));
    }

    /** Courses with a mix of matched and unmatched sections are skipped. */
    public function test_course_with_partially_unmatched_sections_is_skipped(): void {
        $now = 1800000000;
        $this->add_period('PERIOD_A', $now, $now + 30 * DAYSECS);
        $this->getDataGenerator()->create_group(['courseid' => 302, 'name' => 'BIOL 1001 1234567890123']);
        $this->add_section('PERIOD_A', '302', '1234567890123');
        $this->add_section('PERIOD_A', '302', '1234567890999'); // No Moodle group for this section.
        $this->assertSame([], (new manager())->get_eligible_course_groups($now));
        $this->assertSame([], (new manager())->eligible_course_ids($now));
    }

    /** Use the configured number of days with a strict greater-than boundary. */
    public function test_configurable_cutoff_and_boundary(): void {
        $now = 1800000000;
        $this->add_period('PERIOD_A', 0, $now + 10 * DAYSECS);
        $this->add_section('PERIOD_A', '201');
        $service = new manager();
        $this->assertSame([], $service->eligible_course_ids($now));
        set_config('cutoffdays', 9, 'local_lsu_creditchoice');
        $this->assertSame([201], $service->eligible_course_ids($now));
        set_config('cutoffdays', 11, 'local_lsu_creditchoice');
        $this->assertSame([], $service->eligible_course_ids($now));
    }

    /** Zero days still excludes periods ending now or earlier. */
    public function test_zero_cutoff(): void {
        global $DB;
        $now = 1800000000;
        $this->add_period('PERIOD_A', 0, $now);
        $this->add_section('PERIOD_A', '201');
        set_config('cutoffdays', 0, 'local_lsu_creditchoice');
        $this->assertSame([], (new manager())->eligible_course_ids($now));
        $DB->set_field('enrol_wds_periods', 'end_date', $now + 1, ['academic_period_id' => 'PERIOD_A']);
        $this->assertSame([201], (new manager())->eligible_course_ids($now));
    }

    /** Preserve ten days as the default if the new setting has not yet been saved. */
    public function test_unsaved_cutoff_defaults_to_ten_days(): void {
        $now = 1800000000;
        $this->add_period('PERIOD_A', 0, $now + 10 * DAYSECS);
        $this->add_section('PERIOD_A', '201');
        unset_config('cutoffdays', 'local_lsu_creditchoice');
        $this->assertSame([], (new manager())->eligible_course_ids($now));
        $this->assertSame([201], (new manager())->eligible_course_ids($now - 1));
    }

    /** Missing or stale period selections must not broaden the query. */
    public function test_invalid_period_is_rejected(): void {
        set_config('academicperiodid', 'MISSING', 'local_lsu_creditchoice');
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('invalidacademicperiod', 'local_lsu_creditchoice'));
        (new manager())->eligible_course_ids(1800000000);
    }

    /** Reject invalid day counts at runtime as well as on the settings form. */
    public function test_invalid_cutoff_is_rejected(): void {
        $this->add_period('PERIOD_A', 0, 1900000000);
        set_config('cutoffdays', -1, 'local_lsu_creditchoice');
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('invalidcutoffdays', 'local_lsu_creditchoice'));
        (new manager())->eligible_course_ids(1800000000);
    }
}
