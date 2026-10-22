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
 * Validated administrator setting for the period-end cutoff.
 *
 * @package    local_lsu_creditchoice
 * @copyright  2026 Louisiana State University
 * @copyright  2026 Robert Russo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_lsu_creditchoice\admin;

/**
 * Reject invalid day counts before saving plugin configuration.
 *
 * @copyright 2026 Louisiana State University
 * @copyright 2026 Robert Russo
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class setting_cutoffdays extends \admin_setting_configtext {
    /**
     * Validate a nonnegative whole-number value.
     *
     * @param mixed $data Submitted setting value.
     * @return bool|string True on success, otherwise a localised error.
     */
    public function validate($data) {
        if (\local_lsu_creditchoice\manager::validate_cutoff_days($data) === false) {
            return get_string('invalidcutoffdays', 'local_lsu_creditchoice');
        }
        return parent::validate($data);
    }
}
