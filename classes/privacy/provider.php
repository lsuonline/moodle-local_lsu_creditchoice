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
 * Privacy metadata declaration.
 *
 * @package    local_lsu_creditchoice
 * @copyright  2026 Louisiana State University
 * @copyright  2026 Robert Russo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_lsu_creditchoice\privacy;

/**
 * Responses belong to mod_choice; this plugin stores only activity references.
 *
 * @copyright 2026 Louisiana State University
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements \core_privacy\local\metadata\provider {
    /**
     * Declare the subsystem containing responses.
     *
     * @param \core_privacy\local\metadata\collection $collection Metadata collection.
     * @return \core_privacy\local\metadata\collection
     */
    public static function get_metadata(
        \core_privacy\local\metadata\collection $collection
    ): \core_privacy\local\metadata\collection {
        $collection->add_subsystem_link('mod_choice', [], 'privacy:metadata:choice');
        return $collection;
    }
}
