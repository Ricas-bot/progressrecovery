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
 * Recuperação das fichas de inscrição: aprovação, execução e resultado.
 *
 * @package    local_progressrecovery
 * @copyright  2026 IT EDUdigital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_progressrecovery\audit\audit_manager;
use local_progressrecovery\comparison\comparison_manager;
use local_progressrecovery\import\import_manager;
use local_progressrecovery\output\recovery_view;
use local_progressrecovery\recovery\recovery_manager;

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

$id = required_param('id', PARAM_INT);
$start = optional_param('start', 0, PARAM_BOOL);

$url = new moodle_url('/local/progressrecovery/recover.php', ['id' => $id]);
admin_externalpage_setup('local_progressrecovery', '', null, $url);
require_capability('local/progressrecovery:manage', context_system::instance());

$package = import_manager::get_package($id);
$PAGE->set_title(get_string('recovery', 'local_progressrecovery'));
$PAGE->set_heading(get_string('pluginname', 'local_progressrecovery'));

if (optional_param('export', '', PARAM_ALPHA) === 'csv') {
    audit_manager::log('recovery_exported', audit_manager::RESULT_OK, [], $id);
    \core\session\manager::write_close();
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    recovery_view::export_operations($package);
    die();
}

if ($start) {
    require_sesskey();
    $scope = optional_param('scope', recovery_manager::SCOPE_REGISTRATION, PARAM_ALPHANUMEXT);
    recovery_manager::start($package, optional_param('backup', 0, PARAM_BOOL), $scope);
    redirect($url);
}

$compare = comparison_manager::latest_job($id);
$comparedone = $compare !== null && $compare->status === comparison_manager::STATUS_DONE;
$job = recovery_manager::latest_job($id);
$running = $job !== null && $job->status === comparison_manager::STATUS_RUNNING;

$context = [
    'filename' => $package->filename,
    'compareurl' => (new moodle_url('/local/progressrecovery/compare.php', ['id' => $id]))->out(false),
    'compensateurl' => (new moodle_url('/local/progressrecovery/compensate.php', ['id' => $id]))->out(false),
    'exporturl' => (new moodle_url('/local/progressrecovery/recover.php', ['id' => $id, 'export' => 'csv']))->out(false),
    'formurl' => $url->out(false),
    'sesskey' => sesskey(),
    'enabled' => recovery_manager::is_enabled(),
    'comparedone' => $comparedone,
    'running' => $running,
    'hasresult' => $job !== null && $comparedone && !$running,
];
if ($running) {
    $PAGE->requires->js_call_amd('local_progressrecovery/compare', 'init', [
        (new moodle_url('/local/progressrecovery/recover_step.php'))->out(false),
        $url->out(false),
        (int)$job->id,
        sesskey(),
    ]);
}
if ($comparedone && !$running) {
    $allowed = recovery_manager::is_enabled() && $package->status === import_manager::STATUS_VALIDATED && !recovery_manager::is_running($id);
    $context += recovery_view::stages($package, $compare, $allowed);
}
if ($context['hasresult']) {
    $context += recovery_view::result($package, $compare);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('recovery', 'local_progressrecovery'));
echo $OUTPUT->render_from_template('local_progressrecovery/recovery', $context);
echo $OUTPUT->footer();
