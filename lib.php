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
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Local plugin "Database files export" - Library
 *
 * @package    local_databasefilesexport
 * @copyright  2021 Sébastien Mehr, Université de Haute-Alsace <sebastien.mehr@uha.fr>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Fumble with Moodle's global navigation by leveraging Moodle's *_extend_navigation() hook.
 *
 * @param global_navigation $navigation
 */
function local_databasefilesexport_extend_settings_navigation(settings_navigation $nav, context $context) {
    global $PAGE, $CFG, $DB;

    $cm = $PAGE->cm;

    if (!$cm) {
        return false;
    }

    if ($cm->modname != 'data') {
        return false;
    }

    $modcontext = context_module::instance($cm->id);
    if (!has_capability('mod/data:manageentries', $modcontext)) {
        return false;
    }

    if (!$datasettingsnode = $nav->find('modulesettings', navigation_node::TYPE_SETTING)) {
        return false;
    }

    if ($DB->record_exists_sql('SELECT * FROM {data_fields} WHERE dataid = ? AND (type = ? OR type = ?)',
    [$cm->instance, 'file', 'picture'])) {
        $urltext = get_string('exportfiles', 'local_databasefilesexport');
        $url = new moodle_url($CFG->wwwroot . '/mod/data/view.php', array('id' => $cm->id, 'action' => 'download'));
        $node = navigation_node::create($urltext, $url, navigation_node::TYPE_SETTING);
        $datasettingsnode->add_node($node);
    }

    if (isset($_GET['action'])) {
        $actionparam = $_GET['action'];
        if ($actionparam === 'download') {
            local_databasefilesexport_download_pictures();
        }
    }
}

/**
 * Get all database files, zip them and send archive to the browser
 *
 */
function local_databasefilesexport_download_pictures() {
    global $PAGE, $CFG;

    $cm = $PAGE->cm;
    $modcontext = context_module::instance($cm->id);
    $courseshortname = $PAGE->course->shortname;

    // Construct the zip file name.
    $archivename = clean_filename($courseshortname . '-' .
                                $cm->name . '-' .
                                $cm->id . '.zip');

    // More efficient to load this here.
    require_once($CFG->libdir.'/filelib.php');

    // Increase the server timeout to handle the creation and sending of large zip files.
    core_php_time_limit::raise();

    // Build a list of files to zip.
    $filesforzipping = array();

    $fs = get_file_storage();
    $files = $fs->get_area_files($modcontext->id, 'mod_data', 'content');
    foreach ($files as $file) {

        $filename = $file->get_filename();

        // Skipping files without mimetype or thumbnails pictures.
        if ((is_null($file->get_mimetype()) || (substr($filename, 0, 6) === 'thumb_'))) {
            continue;
        }
        $filesforzipping[$filename] = $file;
    }

    $zipfile = local_databasefilesexport_pack_files($filesforzipping);
    send_temp_file($zipfile, $archivename);
}

/**
 * Generate zip file from array of given files.
 * taken from mod/assign/locallib.php
 *
 * @param array $filesforzipping - array of files to pass into archive_to_pathname.
 *                                 This array is indexed by the final file name and each
 *                                 element in the array is an instance of a stored_file object.
 * @return path of temp file - note this returned file does
 *         not have a .zip extension - it is a temp file.
 */
function local_databasefilesexport_pack_files($filesforzipping) {
    global $CFG;
    // Create path for new zip file.
    $tempzip = tempnam($CFG->tempdir . '/', 'data_');
    // Zip files.
    $zipper = new zip_packer();
    if ($zipper->archive_to_pathname($filesforzipping, $tempzip)) {
        return $tempzip;
    }
    return false;
}