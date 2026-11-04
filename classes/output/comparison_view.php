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

use local_progressrecovery\comparison\comparison_manager;
use local_progressrecovery\comparison\conflict_detector;
use local_progressrecovery\comparison\entity_map;

defined('MOODLE_INTERNAL') || die();

/**
 * Relatório de diferenças: dados para o template e exportação.
 *
 * @package    local_progressrecovery
 * @copyright  2026 IT EDUdigital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class comparison_view {
    private const CLASSES = [
        conflict_detector::NEW, conflict_detector::IDENTICAL, conflict_detector::CHANGED, conflict_detector::CONFLICT,
        conflict_detector::INVALID, conflict_detector::DEST_NEWER, conflict_detector::IGNORED, conflict_detector::PENDING_ENROL,
    ];
    private const COMPONENTS = ['activity_completion', 'course_completion', 'grades', 'scorm', 'feedback', 'customcert', 'logs', 'enrolment'];
    /** Linhas máximas da lista de estruturas a rever. */
    private const ATTENTION_LIMIT = 300;

    /**
     * @param \stdClass $job
     * @param array $filters
     * @param int $page
     * @param int $perpage
     * @return array
     */
    public static function report(\stdClass $job, array $filters, int $page, int $perpage): array {
        global $DB;
        $summary = json_decode($job->summary, true);
        $params = json_decode($job->params, true);

        $rows = [];
        $totals = array_fill_keys(self::CLASSES, 0);
        foreach (self::COMPONENTS as $component) {
            if (!isset($summary['components'][$component])) {
                continue;
            }
            $info = $summary['components'][$component];
            $counts = [];
            foreach (self::CLASSES as $class) {
                $counts[$class] = (int)($info['counts'][$class] ?? 0);
                $totals[$class] += $counts[$class];
            }
            $rows[] = [
                'name' => get_string('component_' . $component, 'local_progressrecovery'),
                'found' => array_sum($counts),
                'indestination' => (int)($info['in_destination'] ?? 0),
                'new' => $counts[conflict_detector::NEW],
                'identical' => $counts[conflict_detector::IDENTICAL],
                'changed' => $counts[conflict_detector::CHANGED],
                'conflict' => $counts[conflict_detector::CONFLICT],
                'invalid' => $counts[conflict_detector::INVALID],
                'dest_newer' => $counts[conflict_detector::DEST_NEWER],
                'ignored' => $counts[conflict_detector::IGNORED],
                'pending' => $counts[conflict_detector::PENDING_ENROL],
            ];
        }

        $jobid = (int)$job->id;
        $accounts = array_values($DB->get_records(entity_map::TABLE,
            ['jobid' => $jobid, 'entity' => 'user', 'status' => entity_map::MISSING, 'detail' => 'create'], 'natural_key', 'id, natural_key'));
        $remapped = $DB->count_records(entity_map::TABLE, ['jobid' => $jobid, 'entity' => 'user', 'status' => entity_map::REMAPPED]);
        $viagroup = $DB->count_records_select(comparison_manager::ITEMS, 'jobid = ? AND classification = ? AND reason = ?',
            [$jobid, conflict_detector::PENDING_ENROL, 'via_group']);
        $notgroup = $DB->count_records_select(comparison_manager::ITEMS, 'jobid = ? AND classification = ? AND reason = ?',
            [$jobid, conflict_detector::PENDING_ENROL, 'not_via_group']);

        $attention = [];
        $sql = 'jobid = ? AND (status = ? OR status = ? OR (status = ? AND entity = ?))';
        $found = $DB->get_records_select(entity_map::TABLE, $sql,
            [$jobid, entity_map::INVALID, entity_map::MANUAL, entity_map::EXCLUDED, 'user'], 'entity, natural_key', '*', 0, self::ATTENTION_LIMIT);
        foreach ($found as $entry) {
            $attention[] = [
                'entity' => $entry->entity,
                'natural' => $entry->natural_key,
                'status' => get_string('entitystatus_' . $entry->status, 'local_progressrecovery'),
                'detail' => (string)$entry->detail,
            ];
        }

        list($where, $whereparams) = self::where($jobid, $filters);
        $total = $DB->count_records_sql("SELECT COUNT(*) " . self::from() . " WHERE $where", $whereparams);
        $items = [];
        $records = $DB->get_records_sql("SELECT i.*, e.natural_key AS username, c.shortname " . self::from() . " WHERE $where ORDER BY i.id", $whereparams, $page * $perpage, $perpage);
        foreach ($records as $item) {
            $items[] = self::present($item);
        }

        $courses = [];
        foreach ($DB->get_records_sql("SELECT DISTINCT c.id, c.shortname
                                         FROM {" . comparison_manager::ITEMS . "} i
                                         JOIN {course} c ON c.id = i.courseid
                                        WHERE i.jobid = ? ORDER BY c.shortname", [$jobid]) as $course) {
            $courses[] = ['id' => (int)$course->id, 'name' => $course->shortname, 'selected' => (int)$filters['course'] === (int)$course->id];
        }
        $componentoptions = [];
        foreach (self::COMPONENTS as $component) {
            $componentoptions[] = ['id' => $component, 'name' => get_string('component_' . $component, 'local_progressrecovery'), 'selected' => $filters['component'] === $component];
        }
        $classoptions = [];
        foreach (self::CLASSES as $class) {
            $classoptions[] = ['id' => $class, 'name' => get_string('class_' . $class, 'local_progressrecovery'), 'selected' => $filters['classification'] === $class];
        }
        $exportparams = ['id' => $job->packageid] + array_filter($filters);
        return [
            'rows' => $rows,
            'copytime' => package_view::date((int)$params['copy_epoch']),
            'excluded' => implode(', ', $params['excluded']),
            'finished' => package_view::date((int)$job->timecompleted),
            'hasblocked' => !empty($summary['blocked']),
            'blockedlist' => implode(', ', $summary['blocked']),
            'registrations' => (int)$summary['registration_completions'],
            'accounts' => count($accounts),
            'accountnames' => implode(', ', array_column($accounts, 'natural_key')),
            'remapped' => $remapped,
            'viagroup' => $viagroup,
            'notgroup' => $notgroup,
            'hasattention' => !empty($attention),
            'attention' => $attention,
            'items' => $items,
            'hasitems' => !empty($items),
            'itemtotal' => $total,
            'courses' => $courses,
            'components' => $componentoptions,
            'classes' => $classoptions,
            'username' => $filters['username'],
            'csvurl' => (new \moodle_url('/local/progressrecovery/compare.php', $exportparams + ['export' => 'csv']))->out(false),
            'jsonurl' => (new \moodle_url('/local/progressrecovery/compare.php', $exportparams + ['export' => 'json']))->out(false),
        ];
    }

    /**
     * Exporta os registos filtrados. Nunca inclui respostas de inquéritos: os itens não as guardam.
     *
     * @param \stdClass $job
     * @param array $filters
     * @param string $format csv ou json
     */
    public static function export(\stdClass $job, array $filters, string $format): void {
        global $DB;
        list($where, $params) = self::where((int)$job->id, $filters);
        $rows = $DB->get_recordset_sql("SELECT i.*, e.natural_key AS username, c.shortname " . self::from() . " WHERE $where ORDER BY i.id", $params);
        $columns = ['component', 'source_table', 'role', 'username', 'shortname', 'source_userid', 'dest_userid', 'courseid',
            'source_recordid', 'destination_recordid', 'classification', 'reason', 'detail', 'content_hash'];
        $name = 'comparacao-' . (int)$job->packageid . '-' . (int)$job->id;
        if ($format === 'csv') {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $name . '.csv"');
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $columns, ';');
            foreach ($rows as $row) {
                $line = [];
                foreach ($columns as $column) {
                    $line[] = self::csv_safe((string)$row->$column);
                }
                fputcsv($out, $line, ';');
            }
            fclose($out);
        } else {
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $name . '.json"');
            echo "[\n";
            $first = true;
            foreach ($rows as $row) {
                $line = [];
                foreach ($columns as $column) {
                    $line[$column] = $row->$column;
                }
                echo ($first ? '' : ",\n") . json_encode($line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $first = false;
            }
            echo "\n]\n";
        }
        $rows->close();
    }

    /**
     * Impede que um valor seja interpretado como fórmula ao abrir o CSV numa folha de cálculo.
     *
     * @param string $value
     * @return string
     */
    public static function csv_safe(string $value): string {
        // Os números negativos são dados legítimos (ids de sistema), não fórmulas.
        return ($value !== '' && !is_numeric($value) && strpos('=+-@', $value[0]) !== false) ? "'" . $value : $value;
    }

    private static function from(): string {
        return "FROM {" . comparison_manager::ITEMS . "} i
           LEFT JOIN {" . entity_map::TABLE . "} e ON e.jobid = i.jobid AND e.entity = 'user' AND e.source_id = i.source_userid
           LEFT JOIN {course} c ON c.id = i.courseid";
    }

    private static function where(int $jobid, array $filters): array {
        $where = ['i.jobid = ?'];
        $params = [$jobid];
        if ($filters['component'] !== '') {
            $where[] = 'i.component = ?';
            $params[] = $filters['component'];
        }
        if ($filters['classification'] !== '') {
            $where[] = 'i.classification = ?';
            $params[] = $filters['classification'];
        }
        if ($filters['username'] !== '') {
            $where[] = 'e.natural_key = ?';
            $params[] = $filters['username'];
        }
        if ((int)$filters['course'] > 0) {
            $where[] = 'i.courseid = ?';
            $params[] = (int)$filters['course'];
        }
        return [implode(' AND ', $where), $params];
    }

    private static function present(\stdClass $item): array {
        $reason = '';
        if ($item->reason !== null) {
            $reason = get_string('itemreason_' . $item->reason, 'local_progressrecovery');
        }
        return [
            'component' => get_string('component_' . $item->component, 'local_progressrecovery'),
            'table' => $item->source_table,
            'username' => (string)$item->username,
            'course' => (string)$item->shortname,
            'sourceid' => $item->source_recordid,
            'destid' => $item->destination_recordid,
            'classification' => get_string('class_' . $item->classification, 'local_progressrecovery'),
            'reason' => $reason,
            'detail' => (string)$item->detail,
        ];
    }
}
