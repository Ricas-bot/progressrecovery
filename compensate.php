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
 * Desfaz uma recuperação pelas operações registadas.
 *
 * @package    local_progressrecovery
 * @copyright  2026 IT EDUdigital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_progressrecovery\comparison\comparison_manager;
use local_progressrecovery\import\import_manager;
use local_progressrecovery\output\recovery_view;
use local_progressrecovery\recovery\compensation_manager;
use local_progressrecovery\recovery\recovery_manager;

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

$id = required_param('id', PARAM_INT);
$start = optional_param('start', 0, PARAM_BOOL);

$url = new moodle_url('/local/progressrecovery/compensate.php', ['id' => $id]);
admin_externalpage_setup('local_progressrecovery', '', null, $url);
require_capability('local/progressrecovery:manage', context_system::instance());

$package = import_manager::get_package($id);
$PAGE->set_title(get_string('compensation', 'local_progressrecovery'));
$PAGE->set_heading(get_string('pluginname', 'local_progressrecovery'));

if ($start) {
    require_sesskey();
    compensation_manager::start($package, optional_param('backup', 0, PARAM_BOOL), optional_param('understood', 0, PARAM_BOOL));
    redirect($url);
}

$job = compensation_manager::latest_job($id);
$running = $job !== null && $job->status === comparison_manager::STATUS_RUNNING;
$context = recovery_view::compensation($package, !$running && !recovery_manager::is_running($id)) + [
    'filename' => $package->filename,
    'recoverurl' => (new moodle_url('/local/progressrecovery/recover.php', ['id' => $id]))->out(false),
    'formurl' => $url->out(false),
    'sesskey' => sesskey(),
    'enabled' => recovery_manager::is_enabled(),
    'running' => $running,
    'finished' => $job !== null && $job->status === comparison_manager::STATUS_DONE ? userdate((int)$job->timecompleted) : '',
];
if ($running) {
    $PAGE->requires->js_call_amd('local_progressrecovery/compare', 'init', [
        (new moodle_url('/local/progressrecovery/compensate_step.php'))->out(false),
        $url->out(false),
        (int)$job->id,
        sesskey(),
    ]);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('compensation', 'local_progressrecovery'));
echo $OUTPUT->render_from_template('local_progressrecovery/compensation', $context);
echo $OUTPUT->footer();
