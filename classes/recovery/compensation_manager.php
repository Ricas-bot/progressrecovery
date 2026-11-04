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

namespace local_progressrecovery\recovery;

use local_progressrecovery\audit\audit_manager;
use local_progressrecovery\comparison\comparison_manager;
use local_progressrecovery\import\import_manager;
use local_progressrecovery\import\package_reader;
use local_progressrecovery\security\package_validator;

defined('MOODLE_INTERNAL') || die();

/**
 * Desfaz uma recuperação por lotes, pelas operações registadas e pela ordem inversa das etapas (Fase 5).
 *
 * Cada operação guarda o seu resultado, por isso uma interrupção nunca desfaz nada duas vezes. Uma operação
 * desfeita volta a poder ser recuperada. As contas criadas ficam: podem já ter sido usadas.
 *
 * @package    local_progressrecovery
 * @copyright  2026 IT EDUdigital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class compensation_manager {
    public const JOBTYPE = 'compensate';
    private const BATCH = 500;
    private const GRADE_BATCH = 50;
    /** Os efeitos saem antes dos dados de que dependem. As notas saem antes das conclusões, que ainda as usam. */
    private const STAGES = [
        ['records', 'customcert_issues'], ['records', 'logstore_standard_log'],
        ['grades_mod', 'grade_grades'], ['grades_course', 'grade_grades'],
        ['records', 'course_completions'], ['records', 'course_completion_crit_compl'],
        ['records', 'course_modules_completion'], ['records', 'feedback_completed'],
        ['records', 'scorm_scoes_track'], ['summary', null],
    ];
    /** Muitas linhas pequenas: uma transacção por lote, repetida linha a linha só se o lote falhar. */
    private const BULK = ['logstore_standard_log', 'scorm_scoes_track'];
    /** Só o que a recuperação escreveu. Sem escrita, não há nada para desfazer. */
    private const ACTIVE = [recovery_manager::OP_DONE, recovery_manager::OP_DIFFERS, recovery_manager::OP_REVIEW];
    private const WRITES = ['insert', 'update'];

    /** @var \stdClass */
    private $job;
    /** @var \stdClass */
    private $package;
    /** @var array */
    private $cursor;
    /** @var package_reader */
    private $reader;

    /**
     * @return string[] tabelas tratadas, pela ordem em que são desfeitas
     */
    public static function tables(): array {
        return array_values(array_unique(array_filter(array_column(self::STAGES, 1))));
    }

    /**
     * @param int $packageid
     * @return \stdClass|null última compensação do pacote
     */
    public static function latest_job(int $packageid): ?\stdClass {
        global $DB;
        $jobs = $DB->get_records(comparison_manager::JOBS, ['packageid' => $packageid, 'jobtype' => self::JOBTYPE], 'id DESC', '*', 0, 1);
        return $jobs ? reset($jobs) : null;
    }

    /**
     * O que falta desfazer, por tabela. Inclui as que falharam numa execução anterior.
     *
     * @param int $packageid
     * @return array tabela => ['revert' => n, 'irreversible' => n]
     */
    public static function plan(int $packageid): array {
        global $DB;
        $out = [];
        foreach (self::tables() as $table) {
            $out[$table] = ['revert' => 0, 'irreversible' => 0];
        }
        list($instatus, $params) = $DB->get_in_or_equal(self::ACTIVE);
        list($inaction, $actionparams) = $DB->get_in_or_equal(self::WRITES);
        $rows = $DB->get_recordset_sql("SELECT source_table, action, status, reason, COUNT(*) AS n
                                          FROM {" . recovery_manager::OPS . "}
                                         WHERE packageid = ? AND action $inaction AND (status $instatus OR (status = ? AND reason = ?))
                                      GROUP BY source_table, action, status, reason",
            array_merge([$packageid], $actionparams, $params, [recovery_manager::OP_KEPT, record_reverter::FAILED]));
        foreach ($rows as $row) {
            if (!isset($out[$row->source_table])) {
                continue;
            }
            $irreversible = $row->action === 'update' && in_array($row->source_table, ['scorm_scoes_track', 'feedback_completed'], true);
            $out[$row->source_table][$irreversible ? 'irreversible' : 'revert'] += (int)$row->n;
        }
        $rows->close();
        return $out;
    }

    /**
     * @param \stdClass $package
     * @param bool $backupconfirmed
     * @param bool $understood
     * @return \stdClass job
     */
    public static function start(\stdClass $package, bool $backupconfirmed, bool $understood): \stdClass {
        global $DB, $USER;
        if (!recovery_manager::is_enabled()) {
            throw new \moodle_exception('error_recovery_disabled', 'local_progressrecovery');
        }
        if (!$backupconfirmed) {
            throw new \moodle_exception('error_recovery_backup', 'local_progressrecovery');
        }
        if (!$understood) {
            throw new \moodle_exception('error_compensation_confirm', 'local_progressrecovery');
        }
        if ($package->status !== import_manager::STATUS_VALIDATED
                || !hash_equals($package->package_hash, (string)hash_file('sha256', import_manager::package_path($package->package_uuid)))) {
            throw new \moodle_exception('error_package_changed', 'local_progressrecovery');
        }
        if (recovery_manager::is_running((int)$package->id)) {
            throw new \moodle_exception('error_recovery_running', 'local_progressrecovery');
        }
        $compare = comparison_manager::latest_job((int)$package->id);
        if ($compare !== null && $compare->status === comparison_manager::STATUS_RUNNING) {
            throw new \moodle_exception('error_recovery_compare', 'local_progressrecovery');
        }
        $total = 0;
        foreach (self::plan((int)$package->id) as $counts) {
            $total += $counts['revert'] + $counts['irreversible'];
        }
        $now = time();
        $job = (object)[
            'packageid' => $package->id,
            'jobtype' => self::JOBTYPE,
            'status' => comparison_manager::STATUS_RUNNING,
            'stage_state' => json_encode(['stage' => 0, 'line' => 0]),
            'params' => json_encode(['backup_confirmed' => true]),
            'summary' => null,
            'total_records' => $total,
            'processed_records' => 0,
            'userid' => (int)$USER->id,
            'timestarted' => $now,
            'timecompleted' => null,
            'timemodified' => $now,
        ];
        $job->id = $DB->insert_record(comparison_manager::JOBS, $job);
        audit_manager::log('compensation_started', audit_manager::RESULT_OK, ['job' => (int)$job->id, 'total' => $total, 'backup_confirmed' => true], (int)$package->id);
        return $job;
    }

    /**
     * @param \stdClass $job
     * @param \stdClass $package
     */
    public function __construct(\stdClass $job, \stdClass $package) {
        $this->job = $job;
        $this->package = $package;
        $this->cursor = json_decode($job->stage_state, true);
    }

    /**
     * Processa lotes durante o tempo indicado. Faz sempre pelo menos um lote.
     *
     * @param float $budget segundos
     * @return \stdClass job actualizado
     */
    public function step(float $budget): \stdClass {
        $deadline = microtime(true) + $budget;
        $this->reader = new package_reader();
        if (!$this->reader->open(import_manager::package_path($this->package->package_uuid))) {
            throw new \moodle_exception('error_package_changed', 'local_progressrecovery');
        }
        try {
            do {
                $this->run_batch();
            } while ($this->job->status === comparison_manager::STATUS_RUNNING && microtime(true) < $deadline);
        } finally {
            $this->reader->close();
        }
        return $this->job;
    }

    private function run_batch(): void {
        global $DB;
        $index = (int)$this->cursor['stage'];
        list($type, $table) = self::STAGES[$index];
        $finished = true;
        $processed = 0;
        $read = 0;
        if ($type === 'summary') {
            $this->job->summary = json_encode(['tables' => self::counts((int)$this->package->id)]);
        } else {
            $size = $type === 'grades_course' ? self::GRADE_BATCH : self::BATCH;
            $rows = $this->read_rows($table, $size);
            $read = count($rows);
            $finished = $read < $size;
            if ($rows) {
                $processed = $this->process_rows($table, $type, $rows);
            }
        }
        // O cursor só avança no fim do lote: repetir um lote é seguro, porque as operações tratadas são saltadas.
        if ($finished) {
            $this->cursor = ['stage' => $index + 1, 'line' => 0];
        } else {
            $this->cursor['line'] += $read;
        }
        $this->job->stage_state = json_encode($this->cursor);
        $this->job->processed_records = min((int)$this->job->total_records, (int)$this->job->processed_records + $processed);
        $this->job->timemodified = time();
        if ($this->cursor['stage'] >= count(self::STAGES)) {
            $this->job->status = comparison_manager::STATUS_DONE;
            $this->job->timecompleted = time();
        }
        $DB->update_record(comparison_manager::JOBS, $this->job);
        if ($this->job->status === comparison_manager::STATUS_DONE) {
            audit_manager::log('compensation_finished', audit_manager::RESULT_OK, ['job' => (int)$this->job->id], (int)$this->package->id);
        }
    }

    private function read_rows(string $table, int $size): array {
        $rows = [];
        $line = 0;
        $start = (int)$this->cursor['line'];
        foreach ($this->reader->lines(package_validator::TABLE_FILES[$table], 33554432) as $text) {
            if ($line++ < $start || $text === false || $text === '') {
                continue;
            }
            $rows[] = json_decode($text, true);
            if (count($rows) >= $size) {
                break;
            }
        }
        return $rows;
    }

    private function process_rows(string $table, string $type, array $rows): int {
        global $DB;
        $ids = array_map(function ($row) {
            return (int)$row['sid'];
        }, $rows);
        list($in, $params) = $DB->get_in_or_equal($ids);
        $ops = $DB->get_records_select(recovery_manager::OPS, "packageid = ? AND source_table = ? AND source_recordid $in",
            array_merge([(int)$this->package->id, $table], $params), '', 'source_recordid, id, dest_recordid, action, status, reason, previous');
        $pending = [];
        foreach ($rows as $row) {
            $op = $ops[(int)$row['sid']] ?? null;
            if ($op !== null && self::is_pending($op) && $this->in_stage($table, $type, $op)) {
                $pending[] = [$row, $op];
            }
        }
        if (!$pending) {
            return 0;
        }
        $reverter = new record_reverter((int)$this->package->id);
        if (in_array($table, self::BULK, true)) {
            try {
                $transaction = $DB->start_delegated_transaction();
                foreach ($pending as $entry) {
                    $this->save($entry[1], $reverter->revert($table, $type, $entry[0]['d'], $entry[1]), null);
                }
                $transaction->allow_commit();
                return count($pending);
            } catch (\Throwable $e) {
                // Nada deste lote ficou gravado: repete-se linha a linha, para isolar a que falha.
                $DB->force_transaction_rollback();
                error_log('local_progressrecovery: lote de compensação de ' . $table . ': ' . get_class($e) . ': ' . $e->getMessage());
            }
        }
        foreach ($pending as $entry) {
            try {
                $transaction = $DB->start_delegated_transaction();
                $this->save($entry[1], $reverter->revert($table, $type, $entry[0]['d'], $entry[1]), null);
                $transaction->allow_commit();
            } catch (\Throwable $e) {
                if ($DB->is_transaction_started()) {
                    $DB->force_transaction_rollback();
                }
                error_log('local_progressrecovery: compensação de ' . $table . ' ' . $entry[0]['sid'] . ': ' . get_class($e) . ': ' . $e->getMessage());
                $this->save($entry[1], record_reverter::FAILED, $e instanceof \moodle_exception ? $e->errorcode : get_class($e));
            }
        }
        return count($pending);
    }

    private static function is_pending(\stdClass $op): bool {
        if (!in_array($op->action, self::WRITES, true)) {
            return false;
        }
        return in_array($op->status, self::ACTIVE, true) || ($op->status === recovery_manager::OP_KEPT && $op->reason === record_reverter::FAILED);
    }

    /**
     * As notas das actividades vêm antes dos totais, que dependem delas.
     */
    private function in_stage(string $table, string $type, \stdClass $op): bool {
        global $DB;
        if ($table !== 'grade_grades') {
            return true;
        }
        $iscourse = $DB->record_exists_sql('SELECT 1 FROM {grade_grades} gg JOIN {grade_items} gi ON gi.id = gg.itemid WHERE gg.id = ? AND gi.itemtype = ?', [(int)$op->dest_recordid, 'course']);
        return $iscourse === ($type === 'grades_course');
    }

    private function save(\stdClass $op, ?string $reason, ?string $detail): void {
        global $DB;
        $DB->update_record(recovery_manager::OPS, (object)[
            'id' => (int)$op->id,
            'status' => $reason === null ? recovery_manager::OP_REVERTED : recovery_manager::OP_KEPT,
            'reason' => $reason,
            'detail' => $detail,
            'timemodified' => time(),
        ]);
    }

    /**
     * @param int $packageid
     * @return array tabela => desfeitas e mantidas, por motivo
     */
    public static function counts(int $packageid): array {
        global $DB;
        $out = [];
        $rows = $DB->get_recordset_sql("SELECT source_table, status, reason, COUNT(*) AS n FROM {" . recovery_manager::OPS . "}
                                         WHERE packageid = ? AND status IN (?, ?) GROUP BY source_table, status, reason",
            [$packageid, recovery_manager::OP_REVERTED, recovery_manager::OP_KEPT]);
        foreach ($rows as $row) {
            $key = $row->status === recovery_manager::OP_REVERTED ? 'reverted' : (string)$row->reason;
            $out[$row->source_table][$key] = ($out[$row->source_table][$key] ?? 0) + (int)$row->n;
        }
        $rows->close();
        return $out;
    }
}
