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

use local_progressrecovery\comparison\comparison_manager;
use local_progressrecovery\comparison\conflict_detector;
use local_progressrecovery\comparison\entity_map;

defined('MOODLE_INTERNAL') || die();

/**
 * Sincronização dos grupos dinâmicos depois de recuperar as fichas. O plugin não cria inscrições:
 * coloca as tarefas em fila, que o cron executa sem bloquear a interface.
 *
 * @package    local_progressrecovery
 * @copyright  2026 IT EDUdigital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cohort_sync {
    /** cohort::TYPE_DYNAMIC */
    private const TYPE_DYNAMIC = 2;

    /**
     * Grupos que dependem das fichas recuperadas ou que dão acesso aos cursos com inscrição pendente.
     *
     * @param int[] $courses fichas com conclusões recuperadas
     * @param int $comparejob
     * @return int[] ids dos grupos dinâmicos
     */
    public static function affected(array $courses, int $comparejob): array {
        global $DB;
        $cohorts = [];
        $rows = $DB->get_records_sql("SELECT p.id, c.id AS cohortid, p.value
                                        FROM {cohort_rule_params} p
                                        JOIN {cohort_rules} r ON r.id = p.ruleid
                                        JOIN {cohort_rulesets} rs ON rs.id = r.rulesetid
                                        JOIN {cohort} c ON c.activecollectionid = rs.rulecollectionid
                                       WHERE r.name = ? AND p.name = ? AND c.cohorttype = ?", ['coursecompletionlist', 'listofids', self::TYPE_DYNAMIC]);
        foreach ($rows as $row) {
            if (array_intersect(array_map('intval', explode(',', (string)$row->value)), $courses)) {
                $cohorts[(int)$row->cohortid] = true;
            }
        }
        $pending = $DB->get_records_sql("SELECT DISTINCT courseid FROM {" . comparison_manager::ITEMS . "} WHERE jobid = ? AND classification = ?",
            [$comparejob, conflict_detector::PENDING_ENROL]);
        if ($pending) {
            list($in, $params) = $DB->get_in_or_equal(array_keys($pending));
            $rows = $DB->get_records_sql("SELECT DISTINCT e.customint1
                                            FROM {enrol} e
                                            JOIN {cohort} c ON c.id = e.customint1
                                           WHERE e.enrol = ? AND c.cohorttype = ? AND e.courseid $in", array_merge(['cohort', self::TYPE_DYNAMIC], $params));
            foreach ($rows as $row) {
                $cohorts[(int)$row->customint1] = true;
            }
        }
        $ids = array_keys($cohorts);
        sort($ids);
        return $ids;
    }

    /**
     * Coloca em fila a sincronização de cada grupo e guarda o número de membros, para comparar depois.
     *
     * @param int[] $cohorts
     * @return array[] id, members
     */
    public static function queue(array $cohorts): array {
        global $DB;
        $out = [];
        foreach ($cohorts as $cohortid) {
            $task = new \totara_cohort\task\sync_dynamic_cohort_task();
            $task->set_custom_data($cohortid);
            $task->set_component('totara_cohort');
            \core\task\manager::queue_adhoc_task($task, true);
            $out[] = ['id' => $cohortid, 'members' => $DB->count_records('cohort_members', ['cohortid' => $cohortid])];
        }
        return $out;
    }

    /**
     * Inscrições que a comparação deu como pendentes e que continuam em falta neste momento.
     *
     * @param int $comparejob
     * @return array total, waiting e os pares ainda em falta (username, shortname)
     */
    public static function pending_now(int $comparejob): array {
        global $DB;
        $items = $DB->get_records_sql("SELECT i.id, i.courseid, e.dest_id, e.natural_key, c.shortname
                                         FROM {" . comparison_manager::ITEMS . "} i
                                    LEFT JOIN {" . entity_map::TABLE . "} e ON e.jobid = i.jobid AND e.entity = 'user' AND e.source_id = i.source_userid
                                    LEFT JOIN {course} c ON c.id = i.courseid
                                        WHERE i.jobid = ? AND i.classification = ?", [$comparejob, conflict_detector::PENDING_ENROL]);
        $enrolled = [];
        $users = array_values(array_unique(array_filter(array_map(function ($item) {
            return (int)$item->dest_id;
        }, $items))));
        foreach (array_chunk($users, 500) as $chunk) {
            list($in, $params) = $DB->get_in_or_equal($chunk);
            $rows = $DB->get_records_sql("SELECT ue.id, ue.userid, e.courseid
                                            FROM {user_enrolments} ue
                                            JOIN {enrol} e ON e.id = ue.enrolid
                                           WHERE ue.userid $in AND ue.status = 0 AND e.status = 0", $params);
            foreach ($rows as $row) {
                $enrolled[$row->userid . '|' . $row->courseid] = true;
            }
        }
        $waiting = [];
        foreach ($items as $item) {
            if (!isset($enrolled[(int)$item->dest_id . '|' . (int)$item->courseid])) {
                $waiting[] = ['username' => (string)$item->natural_key, 'shortname' => (string)$item->shortname];
            }
        }
        return ['total' => count($items), 'waiting' => $waiting];
    }
}
