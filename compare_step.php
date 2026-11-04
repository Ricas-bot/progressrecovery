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
 * Um lote de comparação, chamado por AJAX.
 *
 * @package    local_progressrecovery
 * @copyright  2026 IT EDUdigital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_progressrecovery\audit\audit_manager;
use local_progressrecovery\comparison\comparison_manager;
use local_progressrecovery\import\import_manager;

define('AJAX_SCRIPT', true);
require(__DIR__ . '/../../config.php');

require_login(null, false);
require_capability('local/progressrecovery:manage', context_system::instance());
require_sesskey();

$jobid = required_param('job', PARAM_INT);
$job = $DB->get_record(comparison_manager::JOBS, ['id' => $jobid, 'jobtype' => 'compare'], '*', MUST_EXIST);
$package = import_manager::get_package((int)$job->packageid);

// Liberta a sessão: a navegação noutro separador não fica bloqueada durante o lote.
\core\session\manager::write_close();

header('Content-Type: application/json; charset=utf-8');

$lock = \core\lock\lock_config::get_lock_factory('local_progressrecovery')->get_lock('compare', 0);
if (!$lock) {
    http_response_code(409);
    echo json_encode(['busy' => true, 'error' => get_string('busy', 'local_progressrecovery')]);
    die();
}

$error = null;
try {
    if ($job->status === comparison_manager::STATUS_RUNNING) {
        core_php_time_limit::raise(300);
        raise_memory_limit(MEMORY_EXTRA);
        $job = (new comparison_manager($job, $package))->step(15.0);
    }
} catch (Throwable $e) {
    // A transacção do lote é revertida: ao retomar, o lote repete-se sem duplicar nada.
    if ($DB->is_transaction_started()) {
        $DB->force_transaction_rollback();
    }
    debugging('local_progressrecovery: ' . $e->getMessage(), DEBUG_DEVELOPER);
    error_log('local_progressrecovery: ' . get_class($e) . ': ' . $e->getMessage() . ' em ' . $e->getFile() . ':' . $e->getLine());
    audit_manager::log('compare_failed', audit_manager::RESULT_REJECTED, ['job' => $jobid, 'exception' => get_class($e)], (int)$job->packageid);
    $error = get_string('error_compare_step', 'local_progressrecovery');
}
$lock->release();

if ($error !== null) {
    http_response_code(500);
    echo json_encode(['error' => $error]);
    die();
}
$total = max(1, (int)$job->total_records);
echo json_encode([
    'status' => $job->status,
    'processed' => (int)$job->processed_records,
    'total' => (int)$job->total_records,
    'percent' => $job->status === comparison_manager::STATUS_DONE ? 100 : min(99, (int)floor($job->processed_records / $total * 100)),
]);
