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
 * Integration tests for managed Choices.
 *
 * @package    local_lsu_creditchoice
 * @copyright  2026 Louisiana State University
 * @copyright  2026 Robert Russo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_lsu_creditchoice;

/**
 * Tests activity creation, duplicate protection and deadline defaults.
 *
 * @covers \local_lsu_creditchoice\manager
 * @copyright 2026 Louisiana State University
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class manager_test extends \advanced_testcase {
    /**
     * Configure settings and return the service.
     *
     * @return manager
     */
    private function service(): manager {
        global $CFG;
        $this->resetAfterTest();
        $this->setAdminUser();
        $CFG->usetags = 1;
        $collection = \core_tag_area::get_collection('core', 'course_modules');
        $tags = \core_tag_tag::create_if_missing($collection, ['Credit preference'], true);
        $tag = reset($tags);
        set_config('tagid', $tag->id, 'local_lsu_creditchoice');
        set_config('choicename', 'Select Your College Credit Preference', 'local_lsu_creditchoice');
        set_config('description', '<p>Choose credit.</p>', 'local_lsu_creditchoice');
        set_config('yeslabel', 'Yes', 'local_lsu_creditchoice');
        set_config('nolabel', 'No', 'local_lsu_creditchoice');
        set_config('autodefault', 1, 'local_lsu_creditchoice');
        return new manager();
    }

    /** Check configured fields, standard tag and idempotent creation. */
    public function test_creation_is_idempotent(): void {
        global $DB;
        $service = $this->service();
        $course = $this->getDataGenerator()->create_course(['enddate' => time() + DAYSECS]);
        $cmid = $service->create_for_course($course->id, $service->get_settings());
        $record = $DB->get_record('local_lsu_creditchoice', ['courseid' => $course->id], '*', MUST_EXIST);
        $choice = $DB->get_record('choice', ['id' => $record->choiceid], '*', MUST_EXIST);
        $this->assertEquals($course->enddate + 7 * DAYSECS, $choice->timeclose);
        $this->assertEquals(0, $choice->allowupdate);
        $this->assertEquals(0, $choice->allowmultiple);
        $this->assertEquals(0, $choice->showresults);
        $this->assertCount(2, $DB->get_records('choice_options', ['choiceid' => $choice->id]));
        $this->assertNotEmpty(\core_tag_tag::get_item_tags('core', 'course_modules', $cmid));
        $this->assertSame(0, $service->create_for_course($course->id, $service->get_settings()));
        $this->assertEquals(1, $DB->count_records('choice', ['course' => $course->id]));
    }

    /** Preserve Yes, default only missing responses, and do not repeat finalisation. */
    public function test_default_preserves_answers(): void {
        global $DB;
        $service = $this->service();
        $course = $this->getDataGenerator()->create_course(['enddate' => time() + DAYSECS]);
        $cmid = $service->create_for_course($course->id, $service->get_settings());
        $yesuser = $this->getDataGenerator()->create_user();
        $nouser = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($yesuser->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($nouser->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $record = $DB->get_record('local_lsu_creditchoice', ['courseid' => $course->id], '*', MUST_EXIST);
        $choice = choice_get_choice($record->choiceid);
        $cm = get_coursemodule_from_id('choice', $cmid);
        choice_user_submit_response((int)array_key_first($choice->option), $choice, $yesuser->id, $course, $cm);
        $DB->set_field('course', 'enddate', time() - 8 * DAYSECS, ['id' => $course->id]);
        $service->process_existing($record);
        $this->assertEquals(array_key_first($choice->option), $DB->get_field('choice_answers', 'optionid', [
            'choiceid' => $choice->id, 'userid' => $yesuser->id,
        ]));
        $this->assertEquals($record->nooptionid, $DB->get_field('choice_answers', 'optionid', [
            'choiceid' => $choice->id, 'userid' => $nouser->id,
        ]));
        $this->assertFalse($DB->record_exists('choice_answers', ['choiceid' => $choice->id, 'userid' => $teacher->id]));
        $record = $DB->get_record('local_lsu_creditchoice', ['id' => $record->id]);
        $this->assertGreaterThan(0, $record->finalized);
        $service->process_existing($record);
        $this->assertEquals(2, $DB->count_records('choice_answers', ['choiceid' => $choice->id]));
    }

    /** An unset course end date must never create an indefinitely open Choice. */
    public function test_missing_enddate_is_skipped(): void {
        $service = $this->service();
        $course = $this->getDataGenerator()->create_course(['enddate' => 0]);
        $this->assertSame(0, $service->create_for_course($course->id, $service->get_settings()));
    }

    /** Modified option text must block automatic responses. */
    public function test_changed_options_block_defaults(): void {
        global $DB;
        $service = $this->service();
        $course = $this->getDataGenerator()->create_course(['enddate' => time() + DAYSECS]);
        $service->create_for_course($course->id, $service->get_settings());
        $record = $DB->get_record('local_lsu_creditchoice', ['courseid' => $course->id]);
        $DB->set_field('choice_options', 'text', 'Now this means Yes', ['id' => $record->nooptionid]);
        $DB->set_field('course', 'enddate', time() - 8 * DAYSECS, ['id' => $course->id]);
        $this->expectException(\moodle_exception::class);
        $service->process_existing($record);
    }
}
