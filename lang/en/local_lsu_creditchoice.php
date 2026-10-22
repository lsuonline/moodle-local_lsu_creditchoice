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
 * English language strings.
 *
 * @package    local_lsu_creditchoice
 * @copyright  2026 Louisiana State University
 * @copyright  2026 Robert Russo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Basic strings.
$string['academicperiodid'] = 'Academic period';
$string['academicperiodid_desc'] = 'Select the academic_period_id whose courses should receive new Choices. The list comes '
    . 'from the Workday enrol_wds_periods table. No period is selected automatically by date. '
    . 'If the list is empty, check that the Workday integration has populated its periods table. '
    . 'Existing managed Choices continue to be processed when this selection changes.';
$string['autodefault'] = 'Automatically record No after the deadline';
$string['autodefault_desc'] = 'On the first successful cron run after closure, record No for active enrolled users who can choose '
    . 'and cannot read responses. Existing responses are preserved. If disabled, edit the description so it '
    . 'does not promise an automatic response.';
$string['choicename'] = 'Choice name';
$string['choicename_desc'] = 'Applied to newly created activities only.';
$string['cutoffdays'] = 'Period-end cutoff (days)';
$string['cutoffdays_desc'] = 'Create new Choices only while the selected academic period ends more than this many days '
    . 'after the task runs (default: 10). Enter a nonnegative whole number. With 0, the period must still be in the future. '
    . 'This does not change the Choice response deadline of seven days after the Moodle course end date.';
$string['defaultdescription'] = '<p>Review your final grade for this course before making your selection. Choose one:</p><p>Choose '
    . '<strong>Yes</strong> if you want this course grade recorded as college credit and included on your '
    . 'college transcript.<br>Choose <strong>No</strong> if you do not want this course grade recorded as '
    . 'college credit and included on your transcript.</p><p>This choice applies only to this course. Once '
    . 'submitted, your selection cannot be changed. If you do not make a selection by the posted deadline, '
    . 'the default <strong>No college credit</strong> will be selected for you.</p>';
$string['defaultname'] = 'Select Your College Credit Preference';
$string['defaultno'] = 'No, do not record this course grade for college credit.';
$string['defaultyes'] = 'Yes, record this course grade for college credit.';
$string['description'] = 'Choice description';
$string['description_desc'] = 'HTML allowed. Applied to newly created activities only. No embedded file uploads.';
$string['enabled'] = 'Enable automation';
$string['enabled_desc'] = 'Create matching course Choices and process deadlines every 15 minutes. Configure a standard activity '
    . 'tag, select an academic period, and review the period-end cutoff before enabling.';
$string['invalidacademicperiod'] = 'Select an academic period that exists in the Workday enrol_wds_periods table.';
$string['invalidconfig'] = 'Configure a nonempty name, description, two distinct options, and an enabled standard activity tag.';
$string['invalidcutoffdays'] = 'Enter a nonnegative whole number of days within the supported integer range.';
$string['missingtable'] = 'Required Workday table is missing: {$a}.';
$string['nolabel'] = 'Option 2 (No)';
$string['nolabel_desc'] = 'Plain text. The second option is the automatic default for unanswered Choices.';
$string['pluginname'] = 'LSU college credit Choice';
$string['privacy:metadata:choice'] = 'Student selections, including automatic No responses, '
    . 'are stored by the core Choice activity and '
    . 'handled by its privacy provider.';
$string['selectacademicperiod'] = 'Select an academic period';
$string['selecttag'] = 'Select a standard tag';
$string['tagid'] = 'Standard activity tag';
$string['tagid_desc'] = 'Select an existing standard tag from the course activity tag collection. Enable activity tagging and '
    . 'create the tag under Manage tags first. Applied to new activities only.';
$string['taskname'] = 'Create and close LSU college credit Choices';
$string['yeslabel'] = 'Option 1 (Yes)';
$string['yeslabel_desc'] = 'Plain text. The first option always means Yes.';
$string['processingfailed'] = 'Error processing credit Choices; see the task log.';
$string['existingmarker'] = 'Reserved Credit Choice ID number already exists; review the copied activity.';
$string['missingenddate'] = 'Managed course has no end date; set an end date before processing.';
$string['changedchoice'] = 'Managed Choice settings/options changed; automatic defaults require review.';
