<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Carregamento e validação de um pacote.
 *
 * @package    local_progressrecovery
 * @copyright  2026 IT EDUdigital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_progressrecovery\form\upload_form;
use local_progressrecovery\import\import_manager;
use local_progressrecovery\output\package_view;

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_progressrecovery', '', null, new moodle_url('/local/progressrecovery/upload.php'));
require_capability('local/progressrecovery:manage', context_system::instance());

$PAGE->set_title(get_string('upload', 'local_progressrecovery'));
$PAGE->set_heading(get_string('pluginname', 'local_progressrecovery'));

$indexurl = new moodle_url('/local/progressrecovery/index.php');
$form = new upload_form(null, ['maxbytes' => import_manager::max_bytes()]);

if ($form->is_cancelled()) {
    redirect($indexurl);
}

$outcome = null;
if ($data = $form->get_data()) {
    core_php_time_limit::raise(600);
    raise_memory_limit(MEMORY_EXTRA);

    $tempdir = make_request_directory();
    $temppath = $tempdir . '/upload.zip';
    $filename = (string)$form->get_new_filename('package');
    $saved = $form->save_file('package', $temppath, true);
    // A cópia temporária do Totara é apagada: o pacote só fica na área privada do plugin.
    get_file_storage()->delete_area_files(context_user::instance($USER->id)->id, 'user', 'draft', (int)$data->package);

    if ($saved) {
        $outcome = import_manager::process_upload($temppath, $filename);
    } else {
        $outcome = ['accepted' => false, 'checks' => [], 'error' => 'upload', 'id' => null];
    }
    if ($outcome['accepted']) {
        redirect(new moodle_url('/local/progressrecovery/package.php', ['id' => $outcome['id']]),
            get_string('uploadaccepted', 'local_progressrecovery'), null, \core\output\notification::NOTIFY_SUCCESS);
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('upload', 'local_progressrecovery'));
if (!import_manager::has_secret()) {
    echo $OUTPUT->notification(get_string('nosecret', 'local_progressrecovery'), 'notifyproblem');
}
if ($outcome !== null) {
    $message = 'uploadrejected';
    if ($outcome['error'] === 'duplicate') {
        $message = 'uploadduplicate';
    } else if ($outcome['error'] === 'upload') {
        $message = 'uploadfailed';
    }
    echo $OUTPUT->notification(get_string($message, 'local_progressrecovery'), 'notifyproblem');
    echo $OUTPUT->render_from_template('local_progressrecovery/validation_result', [
        'checks' => package_view::checks($outcome['checks']),
        'haschecks' => !empty($outcome['checks']),
    ]);
}
echo html_writer::tag('p', get_string('uploadintro', 'local_progressrecovery', display_size(import_manager::max_bytes())));
$form->display();
echo $OUTPUT->footer();
