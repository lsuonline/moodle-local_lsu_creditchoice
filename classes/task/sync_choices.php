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
 * Synchronise managed credit Choices.
 *
 * @package    local_lsu_creditchoice
 * @copyright  2026 Louisiana State University
 * @copyright  2026 Robert Russo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_lsu_creditchoice\task;

/**
 * Creates Choices and processes expired managed Choices.
 *
 * @copyright 2026 Louisiana State University
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sync_choices extends \core\task\scheduled_task {
    /** @return string Localised task name. */
    public function get_name(): string {
        return get_string('taskname', 'local_lsu_creditchoice');
    }

    /** Execute the scheduled task. */
    public function execute(): void {
        if (!get_config('local_lsu_creditchoice', 'enabled')) {
            return;
        }
        $factory = \core\lock\lock_config::get_lock_factory('local_lsu_creditchoice');
        $lock = $factory->get_lock('sync', 0);
        if (!$lock) {
            mtrace('Credit Choice: another task is running.');
            return;
        }
        try {
            (new \local_lsu_creditchoice\manager())->execute();
        } finally {
            $lock->release();
        }
    }
}
