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
 * Administrator settings.
 *
 * @package    local_lsu_creditchoice
 * @copyright  2026 Louisiana State University
 * @copyright  2026 Robert Russo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Make sure we are admin.
if ($hassiteconfig) {

    // Instantiate the settingspage.
    $settings = new admin_settingpage('local_lsu_creditchoice', get_string('pluginname', 'local_lsu_creditchoice'));

    // Add the settings page to the local plugins settings area.
    $ADMIN->add('localplugins', $settings);
    if ($ADMIN->fulltree) {
        $component = 'local_lsu_creditchoice';

        // Add the enabled checkbox.
        $settings->add(new admin_setting_configcheckbox(
            "$component/enabled", get_string('enabled', $component), get_string('enabled_desc', $component), 0
        ));

        // The name of the choice module.
        $settings->add(new admin_setting_configtext(
            "$component/choicename", get_string('choicename', $component), get_string('choicename_desc', $component),
            get_string('defaultname', $component), PARAM_TEXT, 60
        ));

        // Add the choice module description.
        $settings->add(new admin_setting_confightmleditor(
            "$component/description", get_string('description', $component), get_string('description_desc', $component),
            get_string('defaultdescription', $component)
        ));

        // Define the options.
        foreach (['yeslabel' => 'defaultyes', 'nolabel' => 'defaultno'] as $key => $default) {

            // Add the defined options.
            $settings->add(new admin_setting_configtext(
                "$component/$key", get_string($key, $component), get_string($key . '_desc', $component),
                get_string($default, $component), PARAM_TEXT, 80
            ));
        }

        // Set up the tags.
        $tagchoices = [0 => get_string('selecttag', $component)];

        // Get the tags.
        $collection = core_tag_area::get_collection('core', 'course_modules');
        $tags = $DB->get_records('tag', ['tagcollid' => $collection, 'isstandard' => 1], 'name', 'id,rawname');

        // Add the tags to an array for use later.
        foreach ($tags as $tag) {
            $tagchoices[$tag->id] = $tag->rawname;
        }

        // Add the tags as dropdnown.
        $settings->add(new admin_setting_configselect(
            "$component/tagid", get_string('tagid', $component), get_string('tagid_desc', $component), 0, $tagchoices
        ));

        // Set the items as no-credit option after date.
        $settings->add(new admin_setting_configcheckbox(
            "$component/autodefault", get_string('autodefault', $component), get_string('autodefault_desc', $component), 1
        ));
    }
}
