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

namespace local_progressrecovery\comparison;

use local_progressrecovery\audit\audit_manager;
use local_progressrecovery\import\compatibility_checker;
use local_progressrecovery\import\import_manager;
use local_progressrecovery\import\package_reader;
use local_progressrecovery\security\package_validator;

defined('MOODLE_INTERNAL') || die();

/**
 * Comparação por lotes entre um pacote e esta instalação.
 *
 * Só escreve nas tabelas do plugin. Cada lote grava os resultados e o cursor na mesma transacção,
 * por isso uma interrupção volta ao último lote completo, sem duplicar nada.
 *
 * @package    local_progressrecovery
 * @copyright  2026 IT EDUdigital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class comparison_manager {
    public const JOBS = 'local_progressrecovery_jobs';
    public const ITEMS = 'local_progressrecovery_items';
    public const STATUS_RUNNING = 'running';
    public const STATUS_DONE = 'done';
    private const BATCH = 500;
    /** Instante da cópia para a origem (24/10/2026 00:00, Europe/Lisbon), usado se a definição estiver vazia. */
    public const DEFAULT_COPY_EPOCH = 1792796400;

    /** Etapas, por ordem. As estruturas vêm primeiro; os logs só depois de se saber quem terá conta. */
    private const STAGES = [
        ['entity', 'user'], ['entity', 'course'], ['entity', 'course_modules'], ['entity', 'context'],
        ['entity', 'grade_items'], ['entity', 'course_completion_criteria'], ['entity', 'scorm'], ['entity', 'scorm_scoes'],
        ['entity', 'feedback'], ['entity', 'feedback_item'], ['entity', 'customcert'], ['entity', 'customcert_templates'],
        ['entity', 'customcert_pages'], ['entity', 'customcert_elements'], ['entity', 'files'],
        ['records', 'course_modules_completion'], ['records', 'course_completions'], ['records', 'course_completion_crit_compl'],
        ['records', 'course_completion_history'], ['records', 'grade_grades'], ['records', 'scorm_scoes_track'],
        ['records', 'feedback_completed'], ['records', 'customcert_issues'],
        ['accounts', null], ['records', 'logstore_standard_log'], ['enrolments', null], ['summary', null],
    ];

    /** @var \stdClass */
    private $job;
    /** @var \stdClass */
    private $package;
    /** @var array */
    private $manifest;
    /** @var array */
    private $cursor;
    /** @var package_reader */
    private $reader;
    /** @var entity_map */
    private $map;
    /** @var array dados do pacote lidos uma vez em cada pedido */
    private $extra = [];

    /**
     * @return int instante da cópia usado para reconhecer dados novos no destino
     */
    public static function copy_epoch(): int {
        $value = (int)get_config('local_progressrecovery', 'copyepoch');
        return $value > 0 ? $value : self::DEFAULT_COPY_EPOCH;
    }

    /**
     * @return string[] nomes de utilizador excluídos da recuperação
     */
    public static function excluded_users(): array {
        $value = (string)get_config('local_progressrecovery', 'excludedusers');
        return array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', $value))));
    }

    /**
     * Cria uma nova comparação. A anterior do mesmo pacote é substituída: são só resultados do plugin.
     *
     * @param \stdClass $package
     * @return \stdClass job
     */
    public static function start(\stdClass $package): \stdClass {
        global $DB, $USER;
        if (!hash_equals($package->package_hash, (string)hash_file('sha256', import_manager::package_path($package->package_uuid)))) {
            throw new \moodle_exception('error_package_changed', 'local_progressrecovery');
        }
        $transaction = $DB->start_delegated_transaction();
        foreach ($DB->get_records(self::JOBS, ['packageid' => $package->id, 'jobtype' => 'compare'], '', 'id') as $old) {
            $DB->delete_records(self::ITEMS, ['jobid' => $old->id]);
            $DB->delete_records(entity_map::TABLE, ['jobid' => $old->id]);
            $DB->delete_records(self::JOBS, ['id' => $old->id]);
        }
        $manifest = json_decode($package->manifest, true);
        $total = 0;
        foreach (self::STAGES as $stage) {
            if ($stage[1] !== null && isset($manifest['tables'][$stage[1]])) {
                $total += array_sum(array_map('intval', (array)($manifest['tables'][$stage[1]]['counts'] ?? [])));
            }
        }
        $now = time();
        $job = (object)[
            'packageid' => $package->id,
            'jobtype' => 'compare',
            'status' => self::STATUS_RUNNING,
            'stage_state' => json_encode(['stage' => 0, 'line' => 0]),
            'params' => json_encode(['copy_epoch' => self::copy_epoch(), 'excluded' => self::excluded_users()]),
            'summary' => null,
            'total_records' => $total,
            'processed_records' => 0,
            'userid' => (int)$USER->id,
            'timestarted' => $now,
            'timecompleted' => null,
            'timemodified' => $now,
        ];
        $job->id = $DB->insert_record(self::JOBS, $job);
        $transaction->allow_commit();
        audit_manager::log('compare_started', audit_manager::RESULT_OK, ['job' => (int)$job->id], (int)$package->id);
        return $job;
    }

    /**
     * @param int $packageid
     * @return \stdClass|null última comparação do pacote
     */
    public static function latest_job(int $packageid): ?\stdClass {
        global $DB;
        $jobs = $DB->get_records(self::JOBS, ['packageid' => $packageid, 'jobtype' => 'compare'], 'id DESC', '*', 0, 1);
        return $jobs ? reset($jobs) : null;
    }

    /**
     * @param \stdClass $job
     * @param \stdClass $package
     */
    public function __construct(\stdClass $job, \stdClass $package) {
        $this->job = $job;
        $this->package = $package;
        $this->manifest = json_decode($package->manifest, true);
        $this->cursor = json_decode($job->stage_state, true);
        $this->map = new entity_map((int)$job->id);
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
            } while ($this->job->status === self::STATUS_RUNNING && microtime(true) < $deadline);
        } finally {
            $this->reader->close();
        }
        return $this->job;
    }

    private function run_batch(): void {
        global $DB;
        $index = (int)$this->cursor['stage'];
        list($type, $table) = self::STAGES[$index];
        $transaction = $DB->start_delegated_transaction();
        $finished = true;
        $processed = 0;
        if ($type === 'accounts') {
            $this->stage_accounts();
        } else if ($type === 'enrolments') {
            $this->stage_enrolments();
        } else if ($type === 'summary') {
            $this->stage_summary();
        } else if ($this->stage_applies($table)) {
            $rows = $this->read_rows($table);
            $processed = count($rows);
            $finished = $processed < self::BATCH;
            if ($rows) {
                if ($type === 'entity') {
                    $this->process_entities($table, $rows);
                } else {
                    $this->process_records($table, $rows);
                }
            }
            if ($finished && $table === 'customcert_elements') {
                $this->rollup_templates();
            }
        }
        if ($finished) {
            $this->cursor = ['stage' => $index + 1, 'line' => 0];
        } else {
            $this->cursor['line'] += $processed;
        }
        $this->job->stage_state = json_encode($this->cursor);
        $this->job->processed_records += $processed;
        $this->job->timemodified = time();
        if ($this->cursor['stage'] >= count(self::STAGES)) {
            $this->job->status = self::STATUS_DONE;
            $this->job->timecompleted = time();
        }
        $DB->update_record(self::JOBS, $this->job);
        $transaction->allow_commit();
        if ($this->job->status === self::STATUS_DONE) {
            audit_manager::log('compare_finished', audit_manager::RESULT_OK, ['job' => (int)$this->job->id], (int)$this->package->id);
        }
    }

    private function stage_applies(string $table): bool {
        if (!isset($this->manifest['tables'][$table])) {
            return false;
        }
        $component = conflict_detector::component_of($table);
        if ($component === null) {
            return true;
        }
        return !isset($this->blocked_components()[$component]);
    }

    /**
     * Componentes incompatíveis não são comparados, e a recuperação fica bloqueada para eles.
     */
    private function blocked_components(): array {
        $validation = json_decode($this->package->validation, true);
        $blocked = [];
        foreach ($validation['compatibility']['components'] as $name => $info) {
            if ($info['status'] === compatibility_checker::INCOMPATIBLE) {
                $blocked[$name] = true;
            }
        }
        return $blocked;
    }

    private function read_rows(string $table): array {
        $rows = [];
        $line = 0;
        $start = (int)$this->cursor['line'];
        foreach ($this->reader->lines(package_validator::TABLE_FILES[$table], 33554432) as $text) {
            if ($line++ < $start || $text === false || $text === '') {
                continue;
            }
            $rows[] = json_decode($text, true);
            if (count($rows) >= self::BATCH) {
                break;
            }
        }
        return $rows;
    }

    private function process_entities(string $entity, array $rows): void {
        global $DB;
        $params = json_decode($this->job->params, true);
        $matcher = new entity_matcher($this->map, $params['excluded']);
        $records = [];
        foreach ($matcher->match($entity, $rows) as $entry) {
            $records[] = (object)($entry + ['jobid' => (int)$this->job->id, 'entity' => $entity]);
        }
        $DB->insert_records(entity_map::TABLE, $records);
    }

    private function process_records(string $table, array $rows): void {
        global $DB;
        $params = json_decode($this->job->params, true);
        $period = ['start' => $this->manifest['period']['start_epoch'], 'end' => $this->manifest['period']['end_epoch_exclusive']];
        $detector = new conflict_detector($this->map, (int)$params['copy_epoch'], $period);
        $now = time();
        $records = [];
        foreach ($detector->classify($table, $rows, $this->extra_for($table)) as $item) {
            $records[] = (object)($item + ['jobid' => (int)$this->job->id, 'timecreated' => $now]);
        }
        $DB->insert_records(self::ITEMS, $records);
    }

    /**
     * Dados do pacote de que a classificação precisa para além das linhas do lote.
     */
    private function extra_for(string $table): array {
        if (isset($this->extra[$table])) {
            return $this->extra[$table];
        }
        $extra = [];
        if ($table === 'scorm_scoes_track') {
            $extra['sourcekeys'] = conflict_detector::scorm_source_keys($this->all_rows($table), $this->map);
        } else if (in_array($table, ['course_modules_completion', 'grade_grades'], true)) {
            $extra['sourcekeys'] = $this->extra_for('scorm_scoes_track')['sourcekeys'];
        } else if ($table === 'feedback_completed') {
            $extra['values'] = [];
            foreach ($this->all_rows('feedback_value') as $row) {
                $extra['values'][(int)$row['d']['completed']][] = ['item' => $row['d']['item'], 'value' => $row['d']['value']];
            }
        } else if ($table === 'customcert_issues') {
            $extra['templates_differ'] = $this->certificates_with_template_differences();
        }
        $this->extra[$table] = $extra;
        return $extra;
    }

    private function all_rows(string $table): \Generator {
        if (!isset($this->manifest['tables'][$table])) {
            return;
        }
        foreach ($this->reader->lines(package_validator::TABLE_FILES[$table], 33554432) as $text) {
            if ($text !== false && $text !== '') {
                yield json_decode($text, true);
            }
        }
    }

    private function certificates_with_template_differences(): array {
        global $DB;
        $templates = $DB->get_records(entity_map::TABLE, ['jobid' => $this->job->id, 'entity' => 'customcert_templates', 'status' => entity_map::MANUAL], '', 'source_id');
        $out = [];
        foreach ($this->all_rows('customcert') as $row) {
            if (isset($templates[(int)$row['d']['templateid']])) {
                $out[(int)$row['d']['id']] = true;
            }
        }
        return $out;
    }

    /**
     * Um elemento ou página diferente marca o modelo do certificado, e as emissões ficam para decisão.
     */
    private function rollup_templates(): void {
        global $DB;
        $jobid = (int)$this->job->id;
        $pages = [];
        foreach ($DB->get_records(entity_map::TABLE, ['jobid' => $jobid, 'entity' => 'customcert_pages'], '', 'source_id, parent_id, status') as $page) {
            $pages[(int)$page->source_id] = $page;
        }
        $differ = [];
        foreach ($pages as $page) {
            if ($page->status === entity_map::MANUAL) {
                $differ[(int)$page->parent_id] = true;
            }
        }
        foreach ($DB->get_records(entity_map::TABLE, ['jobid' => $jobid, 'entity' => 'customcert_elements', 'status' => entity_map::MANUAL], '', 'source_id, parent_id') as $element) {
            if (isset($pages[(int)$element->parent_id])) {
                $differ[(int)$pages[(int)$element->parent_id]->parent_id] = true;
            }
        }
        foreach (array_keys($differ) as $template) {
            $DB->set_field(entity_map::TABLE, 'status', entity_map::MANUAL, ['jobid' => $jobid, 'entity' => 'customcert_templates', 'source_id' => $template]);
        }
    }

    /**
     * Só quem tem progresso a recuperar justifica criar uma conta.
     */
    private function stage_accounts(): void {
        global $DB;
        $jobid = (int)$this->job->id;
        $needed = $DB->get_records_sql("SELECT DISTINCT source_userid FROM {" . self::ITEMS . "}
                                         WHERE jobid = ? AND reason = ? AND component <> ?", [$jobid, 'user_to_create', 'logs']);
        $missing = $DB->get_records(entity_map::TABLE, ['jobid' => $jobid, 'entity' => 'user', 'status' => entity_map::MISSING], '', 'id, source_id');
        foreach ($missing as $entry) {
            $DB->set_field(entity_map::TABLE, 'detail', isset($needed[$entry->source_id]) ? 'create' : 'no_progress', ['id' => $entry->id]);
        }
        $this->map = new entity_map($jobid);
    }

    /**
     * Quem tem progresso num curso em que não está inscrito no destino. O plugin não cria inscrições:
     * seguem o modelo dos grupos dinâmicos, depois de recuperadas as fichas. O progresso do período que
     * já está no destino também conta: recuperado, continua inacessível enquanto não houver inscrição.
     */
    private function stage_enrolments(): void {
        global $DB;
        $jobid = (int)$this->job->id;
        $pairs = $DB->get_records_sql("SELECT DISTINCT " . $DB->sql_concat('source_userid', "'|'", 'courseid') . " AS pairkey, source_userid, dest_userid, courseid
                                         FROM {" . self::ITEMS . "}
                                        WHERE jobid = ? AND (classification IN (?, ?) OR (classification = ? AND role = ?))
                                              AND component <> ? AND courseid IS NOT NULL",
            [$jobid, conflict_detector::NEW, conflict_detector::CHANGED, conflict_detector::IDENTICAL, 'changed', 'logs']);
        if (!$pairs) {
            return;
        }
        $enrolled = [];
        $destusers = array_values(array_unique(array_filter(array_map(function ($p) {
            return (int)$p->dest_userid;
        }, $pairs))));
        foreach (array_chunk($destusers, 500) as $chunk) {
            list($in, $params) = $DB->get_in_or_equal($chunk);
            $found = $DB->get_records_sql("SELECT ue.id, ue.userid, e.courseid
                                             FROM {user_enrolments} ue
                                             JOIN {enrol} e ON e.id = ue.enrolid
                                            WHERE ue.userid $in AND ue.status = 0 AND e.status = 0", $params);
            foreach ($found as $row) {
                $enrolled[$row->userid . '|' . $row->courseid] = true;
            }
        }
        $sourcemethods = [];
        foreach ($this->all_rows('user_enrolments') as $row) {
            $sourcemethods[(int)$row['d']['userid'] . '|' . (int)$row['d']['enrol_courseid']] = $row['d']['enrol_method'];
        }
        $now = time();
        $items = [];
        foreach ($pairs as $pair) {
            if ($pair->dest_userid !== null && isset($enrolled[$pair->dest_userid . '|' . $pair->courseid])) {
                continue;
            }
            $method = $sourcemethods[$pair->source_userid . '|' . $pair->courseid] ?? null;
            $items[] = (object)[
                'jobid' => $jobid,
                'component' => 'enrolment',
                'source_table' => 'user_enrolments',
                'source_recordid' => 0,
                'destination_recordid' => null,
                'source_userid' => (int)$pair->source_userid,
                'dest_userid' => $pair->dest_userid === null ? null : (int)$pair->dest_userid,
                'courseid' => (int)$pair->courseid,
                'content_hash' => '',
                'role' => 'structure',
                'classification' => conflict_detector::PENDING_ENROL,
                'reason' => $method === 'cohort' ? 'via_group' : 'not_via_group',
                'detail' => $method,
                'timecreated' => $now,
            ];
        }
        if ($items) {
            $DB->insert_records(self::ITEMS, $items);
        }
    }

    private function stage_summary(): void {
        global $DB;
        $jobid = (int)$this->job->id;
        $components = [];
        $rows = $DB->get_recordset_sql("SELECT component, classification, COUNT(*) AS n, COUNT(destination_recordid) AS indest
                                          FROM {" . self::ITEMS . "} WHERE jobid = ? GROUP BY component, classification", [$jobid]);
        foreach ($rows as $row) {
            $components[$row->component]['counts'][$row->classification] = (int)$row->n;
            $components[$row->component]['in_destination'] = ($components[$row->component]['in_destination'] ?? 0) + (int)$row->indest;
        }
        $rows->close();
        $entities = [];
        $rows = $DB->get_recordset_sql("SELECT entity, status, detail, COUNT(*) AS n FROM {" . entity_map::TABLE . "} WHERE jobid = ? GROUP BY entity, status, detail", [$jobid]);
        foreach ($rows as $row) {
            $entities[$row->entity][] = ['status' => $row->status, 'detail' => $row->detail, 'n' => (int)$row->n];
        }
        $rows->close();
        $fichas = self::registration_courses();
        $recoverable = 0;
        if ($fichas) {
            list($in, $params) = $DB->get_in_or_equal($fichas);
            $recoverable = $DB->count_records_select(self::ITEMS, "jobid = ? AND source_table = ? AND classification IN (?, ?) AND courseid $in",
                array_merge([$jobid, 'course_completions', conflict_detector::NEW, conflict_detector::CHANGED], $params));
        }
        $this->job->summary = json_encode([
            'components' => $components,
            'entities' => $entities,
            'blocked' => array_keys($this->blocked_components()),
            'registration_courses' => $fichas,
            'registration_completions' => $recoverable,
        ]);
    }

    /**
     * Cursos de que os grupos dinâmicos dependem (as fichas de inscrição), lidos das regras do destino.
     *
     * @return int[]
     */
    public static function registration_courses(): array {
        global $DB;
        $rows = $DB->get_records_sql("SELECT p.id, p.value
                                        FROM {cohort_rule_params} p
                                        JOIN {cohort_rules} r ON r.id = p.ruleid
                                        JOIN {cohort_rulesets} rs ON rs.id = r.rulesetid
                                        JOIN {cohort} c ON c.activecollectionid = rs.rulecollectionid
                                       WHERE r.name = ? AND p.name = ?", ['coursecompletionlist', 'listofids']);
        $ids = [];
        foreach ($rows as $row) {
            foreach (explode(',', (string)$row->value) as $id) {
                if ((int)$id > 0) {
                    $ids[(int)$id] = (int)$id;
                }
            }
        }
        sort($ids);
        return $ids;
    }
}
