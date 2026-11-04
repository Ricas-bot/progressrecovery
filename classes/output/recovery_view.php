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

namespace local_progressrecovery\output;

use local_progressrecovery\import\import_manager;
use local_progressrecovery\recovery\compensation_manager;
use local_progressrecovery\comparison\comparison_manager;
use local_progressrecovery\comparison\conflict_detector;
use local_progressrecovery\comparison\entity_map;
use local_progressrecovery\recovery\cohort_sync;
use local_progressrecovery\recovery\recovery_manager;

defined('MOODLE_INTERNAL') || die();

/**
 * Página de recuperação: o que falta fazer em cada etapa e o resultado acumulado.
 *
 * @package    local_progressrecovery
 * @copyright  2026 IT EDUdigital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class recovery_view {
    private const TABLES = ['user', 'feedback_completed', 'course_modules_completion', 'course_completion_crit_compl', 'course_completions', 'scorm_scoes_track', 'grade_grades', 'logstore_standard_log', 'customcert_issues'];
    /** Linhas máximas das listas de registos saltados e falhados. */
    private const LIST_LIMIT = 200;

    /**
     * O que falta em cada etapa. Uma etapa só fica disponível depois de concluída a anterior.
     *
     * @param \stdClass $package
     * @param \stdClass $compare comparação concluída
     * @param bool $allowed se se pode iniciar uma recuperação neste momento
     * @return array
     */
    public static function stages(\stdClass $package, \stdClass $compare, bool $allowed): array {
        global $DB;
        $stages = [];
        $previousdone = true;
        foreach (recovery_manager::SCOPES as $scope) {
            $counts = recovery_manager::remaining((int)$package->id, $compare, $scope);
            $rows = [];
            $remaining = 0;
            foreach (recovery_manager::scope_tables($scope) as $table) {
                $new = (int)($counts[$table][conflict_detector::NEW] ?? 0);
                $changed = (int)($counts[$table][conflict_detector::CHANGED] ?? 0);
                $remaining += $new + $changed;
                $rows[] = ['table' => $table, 'new' => $new, 'changed' => $changed];
            }
            $names = '';
            $accounts = [];
            if ($scope === recovery_manager::SCOPE_REGISTRATION) {
                $courses = recovery_manager::scope_courses($compare, $scope);
                if ($courses) {
                    list($in, $params) = $DB->get_in_or_equal($courses);
                    $names = implode(', ', array_column($DB->get_records_select('course', "id $in", $params, 'id', 'id, shortname'), 'shortname'));
                }
                $accounts = $DB->get_records(entity_map::TABLE, ['jobid' => $compare->id, 'entity' => 'user', 'status' => entity_map::MISSING, 'detail' => 'create'], 'natural_key', 'id, natural_key');
                $remaining += count($accounts);
            }
            $review = $scope === recovery_manager::SCOPE_CERTIFICATES ? recovery_manager::review_count((int)$package->id) : 0;
            $remaining += $review;
            $stages[] = [
                'scope' => $scope,
                'title' => get_string('scope_' . $scope, 'local_progressrecovery'),
                'intro' => get_string('scope_' . $scope . '_intro', 'local_progressrecovery'),
                'action' => get_string('scope_' . $scope . '_start', 'local_progressrecovery'),
                'rows' => $rows,
                'hasfichas' => $names !== '',
                'fichas' => $names,
                'isregistration' => $scope === recovery_manager::SCOPE_REGISTRATION,
                'accounts' => count($accounts),
                'accountnames' => implode(', ', array_column($accounts, 'natural_key')),
                'review' => $review,
                'hasremaining' => $remaining > 0,
                'canstart' => $allowed && $remaining > 0 && $previousdone,
                'waiting' => $remaining > 0 && !$previousdone,
            ];
            $previousdone = recovery_manager::is_scope_done((int)$package->id, $scope);
        }
        return ['stages' => $stages];
    }

    /**
     * @param \stdClass $package
     * @param bool $free sem nenhuma recuperação nem compensação a correr
     * @return array plano, resultado e registos mantidos da compensação
     */
    public static function compensation(\stdClass $package, bool $free): array {
        global $DB;
        $plan = compensation_manager::plan((int)$package->id);
        $counts = compensation_manager::counts((int)$package->id);
        $rows = [];
        $remaining = 0;
        foreach ($plan as $table => $row) {
            $done = $counts[$table] ?? [];
            $reverted = (int)($done['reverted'] ?? 0);
            $remaining += $row['revert'] + $row['irreversible'];
            $rows[] = [
                'table' => $table,
                'revert' => $row['revert'],
                'irreversible' => $row['irreversible'],
                'reverted' => $reverted,
                'kept' => array_sum($done) - $reverted,
            ];
        }
        $notes = [];
        $found = $DB->get_records(recovery_manager::OPS, ['packageid' => (int)$package->id, 'status' => recovery_manager::OP_KEPT],
            'source_table, source_recordid', 'id, source_table, source_recordid, reason, detail', 0, self::LIST_LIMIT);
        foreach ($found as $op) {
            $notes[] = ['table' => $op->source_table, 'sourceid' => $op->source_recordid, 'reason' => (string)$op->reason, 'detail' => (string)$op->detail];
        }
        return [
            'rows' => $rows,
            'notes' => $notes,
            'hasnotes' => (bool)$notes,
            'canstart' => $free && $remaining > 0 && recovery_manager::is_enabled() && $package->status === import_manager::STATUS_VALIDATED,
        ];
    }

    /**
     * Relatório final das operações: nunca inclui os valores anteriores.
     *
     * @param \stdClass $package
     */
    public static function export_operations(\stdClass $package): void {
        global $DB;
        $columns = ['source_table', 'source_recordid', 'dest_table', 'dest_recordid', 'action', 'status', 'reason', 'detail', 'timemodified'];
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="operacoes-' . (int)$package->id . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $columns, ';');
        $rows = $DB->get_recordset_sql('SELECT id, ' . implode(', ', $columns) . ' FROM {' . recovery_manager::OPS . '} WHERE packageid = ? ORDER BY id', [(int)$package->id]);
        foreach ($rows as $row) {
            $line = [];
            foreach ($columns as $column) {
                $line[] = comparison_view::csv_safe((string)$row->$column);
            }
            fputcsv($out, $line, ';');
        }
        $rows->close();
        fclose($out);
    }

    /**
     * @param \stdClass $package
     * @param \stdClass $compare comparação actual, de onde vêm as inscrições pendentes
     * @return array
     */
    public static function result(\stdClass $package, \stdClass $compare): array {
        global $DB;
        $counts = [];
        $found = $DB->get_recordset_sql("SELECT source_table, status, action, COUNT(*) AS n FROM {" . recovery_manager::OPS . "}
                                          WHERE packageid = ? GROUP BY source_table, status, action", [(int)$package->id]);
        foreach ($found as $row) {
            $key = $row->status === recovery_manager::OP_DONE ? (string)$row->action : $row->status;
            $counts[$row->source_table][$key] = ($counts[$row->source_table][$key] ?? 0) + (int)$row->n;
        }
        $found->close();
        $rows = [];
        foreach (self::TABLES as $table) {
            $count = $counts[$table] ?? [];
            $rows[] = [
                'table' => $table,
                'created' => (int)($count['insert'] ?? 0) + (int)($count['create'] ?? 0),
                'updated' => (int)($count['update'] ?? 0),
                'skipped' => (int)($count[recovery_manager::OP_SKIPPED] ?? 0),
                'failed' => (int)($count[recovery_manager::OP_FAILED] ?? 0),
                'differs' => (int)($count[recovery_manager::OP_DIFFERS] ?? 0),
                'review' => (int)($count[recovery_manager::OP_REVIEW] ?? 0),
            ];
        }
        $notes = [];
        $found = $DB->get_records_select(recovery_manager::OPS, 'packageid = ? AND status <> ? AND status <> ?', [(int)$package->id, recovery_manager::OP_DONE, recovery_manager::OP_REVERTED],
            'status, source_table, source_recordid', 'id, source_table, source_recordid, status, reason, detail', 0, self::LIST_LIMIT);
        foreach ($found as $op) {
            $notes[] = [
                'table' => $op->source_table,
                'sourceid' => $op->source_recordid,
                'status' => get_string('opstatus_' . $op->status, 'local_progressrecovery'),
                'reason' => (string)$op->reason,
                'detail' => (string)$op->detail,
            ];
        }
        $latest = recovery_manager::latest_job((int)$package->id);
        $registration = recovery_manager::latest_job((int)$package->id, recovery_manager::SCOPE_REGISTRATION);
        $cohorts = [];
        foreach ($registration ? (json_decode($registration->params, true)['cohorts'] ?? []) : [] as $cohort) {
            $record = $DB->get_record('cohort', ['id' => $cohort['id']], 'id, name');
            $cohorts[] = [
                'name' => $record ? $record->name : (string)$cohort['id'],
                'before' => (int)$cohort['members'],
                'now' => $DB->count_records('cohort_members', ['cohortid' => $cohort['id']]),
            ];
        }
        $pending = cohort_sync::pending_now((int)$compare->id);
        return [
            'resultrows' => $rows,
            'hasnotes' => !empty($notes),
            'notes' => $notes,
            'hascohorts' => !empty($cohorts),
            'cohorts' => $cohorts,
            'finished' => $latest && $latest->status === comparison_manager::STATUS_DONE ? package_view::date((int)$latest->timecompleted) : '',
            'pendingtotal' => $pending['total'],
            'pendingwaiting' => count($pending['waiting']),
            'haswaiting' => !empty($pending['waiting']),
            'waiting' => array_slice($pending['waiting'], 0, self::LIST_LIMIT),
        ];
    }
}
