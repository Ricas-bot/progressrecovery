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
 * Comparação de um pacote com esta instalação: execução, relatório e exportação.
 *
 * @package    local_progressrecovery
 * @copyright  2026 IT EDUdigital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_progressrecovery\audit\audit_manager;
use local_progressrecovery\comparison\comparison_manager;
use local_progressrecovery\import\import_manager;
use local_progressrecovery\output\comparison_view;
use local_progressrecovery\recovery\recovery_manager;

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

$id = required_param('id', PARAM_INT);
$start = optional_param('start', 0, PARAM_BOOL);
$export = optional_param('export', '', PARAM_ALPHA);
$filters = [
    'component' => optional_param('component', '', PARAM_ALPHANUMEXT),
    'classification' => optional_param('classification', '', PARAM_ALPHANUMEXT),
    'username' => trim(optional_param('username', '', PARAM_RAW_TRIMMED)),
    'course' => optional_param('course', 0, PARAM_INT),
];
$page = optional_param('page', 0, PARAM_INT);
$perpage = 50;

$url = new moodle_url('/local/progressrecovery/compare.php', ['id' => $id]);
admin_externalpage_setup('local_progressrecovery', '', null, $url);
require_capability('local/progressrecovery:manage', context_system::instance());

$package = import_manager::get_package($id);
$PAGE->set_title(get_string('comparison', 'local_progressrecovery'));
$PAGE->set_heading(get_string('pluginname', 'local_progressrecovery'));

if ($start) {
    require_sesskey();
    // A recuperação em curso usa os resultados desta comparação.
    if ($package->status === import_manager::STATUS_VALIDATED && !recovery_manager::is_running($id)) {
        comparison_manager::start($package);
    }
    redirect($url);
}

$job = comparison_manager::latest_job($id);

if (in_array($export, ['csv', 'json'], true) && $job && $job->status === comparison_manager::STATUS_DONE) {
    audit_manager::log('compare_exported', audit_manager::RESULT_OK, ['format' => $export, 'job' => (int)$job->id], $id);
    \core\session\manager::write_close();
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    comparison_view::export($job, $filters, $export);
    die();
}

$context = [
    'id' => $id,
    'filename' => $package->filename,
    'packageurl' => (new moodle_url('/local/progressrecovery/package.php', ['id' => $id]))->out(false),
    'formurl' => $url->out(false),
    'sesskey' => sesskey(),
    'isactive' => $package->status === import_manager::STATUS_VALIDATED,
    'hasjob' => $job !== null,
    'running' => $job !== null && $job->status === comparison_manager::STATUS_RUNNING,
    'done' => $job !== null && $job->status === comparison_manager::STATUS_DONE,
    'recoverurl' => (new moodle_url('/local/progressrecovery/recover.php', ['id' => $id]))->out(false),
];
if ($context['running']) {
    $PAGE->requires->js_call_amd('local_progressrecovery/compare', 'init', [
        (new moodle_url('/local/progressrecovery/compare_step.php'))->out(false),
        $url->out(false),
        (int)$job->id,
        sesskey(),
    ]);
}
if ($context['done']) {
    $context += comparison_view::report($job, $filters, $page, $perpage);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('comparison', 'local_progressrecovery'));
echo $OUTPUT->render_from_template('local_progressrecovery/comparison', $context);
if ($context['done'] && $context['itemtotal'] > $perpage) {
    echo $OUTPUT->paging_bar($context['itemtotal'], $page, $perpage, new moodle_url($url, array_filter($filters)));
}
echo $OUTPUT->footer();
