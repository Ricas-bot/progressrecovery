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
use local_progressrecovery\comparison\conflict_detector;
use local_progressrecovery\comparison\entity_map;
use local_progressrecovery\import\import_manager;
use local_progressrecovery\import\package_reader;
use local_progressrecovery\security\package_validator;

defined('MOODLE_INTERNAL') || die();

/**
 * Recuperação por lotes, etapa a etapa (Fase 4). Fichas de inscrição: contas, Feedback, conclusão da
 * actividade, critérios e conclusão do curso, seguidos da sincronização dos grupos. SCORM: as linhas de
 * tentativa, sem notas nem conclusões. Feedback e conclusões: os restantes cursos, com as datas da origem.
 * Notas: calculadas pelo Totara a partir das tentativas, primeiro as actividades e depois os totais.
 * Logs: os eventos do período, com os ids do destino e a data original.
 * Certificados: as emissões, com o código original, seguidas da confirmação de que o formando consegue obter o PDF.
 *
 * Cada registo tem a sua operação em local_progressrecovery_ops, por isso uma interrupção nunca duplica
 * nada e uma falha num registo não impede os outros. Cada registo volta a ser classificado no momento
 * da escrita, porque o destino pode ter mudado desde a comparação.
 *
 * @package    local_progressrecovery
 * @copyright  2026 IT EDUdigital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class recovery_manager {
    public const OPS = 'local_progressrecovery_ops';
    public const OP_DONE = 'done';
    public const OP_SKIPPED = 'skipped';
    public const OP_FAILED = 'failed';
    public const OP_DIFFERS = 'differs';
    /** Emissão gravada, mas o formando ainda não consegue obter o certificado. Repetir a etapa volta a verificar. */
    public const OP_REVIEW = 'review';
    /** Escrita desfeita pela compensação. Pode voltar a ser recuperada. */
    public const OP_REVERTED = 'reverted';
    /** A compensação manteve o registo. Não volta a ser recuperado, para não apagar o que mudou entretanto. */
    public const OP_KEPT = 'kept';
    public const JOBTYPE = 'recover';
    public const SCOPE_REGISTRATION = 'registration';
    public const SCOPE_SCORM = 'scorm';
    public const SCOPE_COMPLETIONS = 'completions';
    public const SCOPE_GRADES = 'grades';
    public const SCOPE_LOGS = 'logs';
    public const SCOPE_CERTIFICATES = 'certificates';
    /** Etapas disponíveis, pela ordem em que têm de ser feitas. */
    public const SCOPES = [self::SCOPE_REGISTRATION, self::SCOPE_SCORM, self::SCOPE_COMPLETIONS, self::SCOPE_GRADES, self::SCOPE_LOGS, self::SCOPE_CERTIFICATES];
    private const BATCH = 500;
    /** Cada nota recalcula os totais do utilizador, por isso os lotes são mais pequenos. */
    private const GRADE_BATCH = 50;
    /** Gerar um PDF pode demorar alguns segundos. */
    private const CERTIFICATE_BATCH = 5;
    /** Motivo gravado numa emissão já confirmada, que não volta a ser verificada. */
    private const CHECK_OK = 'pdf_ok';

    /** Pela ordem da secção 10 da análise: primeiro os dados de origem, depois os efeitos. */
    private const STAGES = [
        self::SCOPE_REGISTRATION => [
            ['accounts', null],
            ['records', 'feedback_completed'], ['records', 'course_modules_completion'],
            ['records', 'course_completion_crit_compl'], ['records', 'course_completions'],
            ['cohorts', null], ['summary', null],
        ],
        self::SCOPE_SCORM => [
            ['records', 'scorm_scoes_track'], ['summary', null],
        ],
        self::SCOPE_COMPLETIONS => [
            ['records', 'feedback_completed'], ['records', 'course_modules_completion'],
            ['records', 'course_completion_crit_compl'], ['records', 'course_completions'],
            ['summary', null],
        ],
        self::SCOPE_GRADES => [
            ['grades_mod', 'grade_grades'], ['grades_course', 'grade_grades'], ['summary', null],
        ],
        self::SCOPE_LOGS => [
            ['records', 'logstore_standard_log'], ['summary', null],
        ],
        self::SCOPE_CERTIFICATES => [
            ['records', 'customcert_issues'], ['certificate_check', 'customcert_issues'], ['summary', null],
        ],
    ];
    /** Muitas linhas pequenas: uma transacção por lote, repetida linha a linha só se o lote falhar. */
    private const BULK = ['scorm_scoes_track', 'logstore_standard_log'];

    /** @var \stdClass */
    private $job;
    /** @var \stdClass */
    private $package;
    /** @var array */
    private $manifest;
    /** @var array */
    private $params;
    /** @var array */
    private $cursor;
    /** @var package_reader */
    private $reader;
    /** @var array|null feedback_value do pacote, por resposta */
    private $values = null;
    /** @var array|null chaves SCORM do pacote, lidas uma vez em cada pedido */
    private $sourcekeys = null;

    /**
     * @return bool se a recuperação foi autorizada nesta instalação
     */
    public static function is_enabled(): bool {
        return (bool)get_config('local_progressrecovery', 'allowrecovery');
    }

    /**
     * @param int $packageid
     * @param string|null $scope só esta etapa
     * @return \stdClass|null última recuperação do pacote
     */
    public static function latest_job(int $packageid, ?string $scope = null): ?\stdClass {
        global $DB;
        foreach ($DB->get_records(comparison_manager::JOBS, ['packageid' => $packageid, 'jobtype' => self::JOBTYPE], 'id DESC') as $job) {
            if ($scope === null || json_decode($job->params, true)['scope'] === $scope) {
                return $job;
            }
        }
        return null;
    }

    /**
     * @param int $packageid
     * @return bool se há uma recuperação ou uma compensação a meio
     */
    public static function is_running(int $packageid): bool {
        global $DB;
        return $DB->count_records_select(comparison_manager::JOBS, 'packageid = ? AND status = ? AND jobtype IN (?, ?)',
            [$packageid, comparison_manager::STATUS_RUNNING, self::JOBTYPE, compensation_manager::JOBTYPE]) > 0;
    }

    /**
     * @param int $packageid
     * @param string $scope
     * @return bool se a etapa foi concluída pelo menos uma vez
     */
    public static function is_scope_done(int $packageid, string $scope): bool {
        global $DB;
        foreach ($DB->get_records(comparison_manager::JOBS, ['packageid' => $packageid, 'jobtype' => self::JOBTYPE, 'status' => comparison_manager::STATUS_DONE], '', 'id, params') as $job) {
            if (json_decode($job->params, true)['scope'] === $scope) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param string $scope
     * @return string[] tabelas escritas pela etapa
     */
    public static function scope_tables(string $scope): array {
        return array_values(array_unique(array_filter(array_column(self::STAGES[$scope], 1))));
    }

    /**
     * Cursos abrangidos por uma etapa. As fichas vêm das regras dos grupos; as outras etapas abrangem todos.
     *
     * @param \stdClass $compare
     * @param string $scope
     * @return int[]|null null quando não há limite
     */
    public static function scope_courses(\stdClass $compare, string $scope): ?array {
        if ($scope !== self::SCOPE_REGISTRATION) {
            return null;
        }
        return array_map('intval', json_decode($compare->summary, true)['registration_courses']);
    }

    /**
     * Registos que falta recuperar, por tabela e classificação. O que já foi feito não conta.
     *
     * @param int $packageid
     * @param \stdClass $compare
     * @param string $scope
     * @return array
     */
    public static function remaining(int $packageid, \stdClass $compare, string $scope): array {
        global $DB;
        $out = [];
        $courses = self::scope_courses($compare, $scope);
        if ($courses === []) {
            return $out;
        }
        $coursesql = '';
        $courseparams = [];
        if ($courses !== null) {
            list($in, $courseparams) = $DB->get_in_or_equal($courses);
            $coursesql = "AND i.courseid $in";
        }
        list($intable, $paramstable) = $DB->get_in_or_equal(self::scope_tables($scope));
        $rows = $DB->get_recordset_sql("SELECT i.source_table, i.classification, COUNT(*) AS n
                                          FROM {" . comparison_manager::ITEMS . "} i
                                     LEFT JOIN {" . self::OPS . "} o ON o.packageid = ? AND o.source_table = i.source_table
                                               AND o.source_recordid = i.source_recordid AND o.status IN (?, ?, ?, ?)
                                         WHERE i.jobid = ? $coursesql AND i.source_table $intable AND i.classification IN (?, ?) AND o.id IS NULL
                                      GROUP BY i.source_table, i.classification",
            array_merge([$packageid, self::OP_DONE, self::OP_DIFFERS, self::OP_REVIEW, self::OP_KEPT, (int)$compare->id], $courseparams, $paramstable, [conflict_detector::NEW, conflict_detector::CHANGED]));
        foreach ($rows as $row) {
            $out[$row->source_table][$row->classification] = (int)$row->n;
        }
        $rows->close();
        return $out;
    }

    /**
     * @param int $packageid
     * @return int emissões gravadas que ainda precisam de intervenção
     */
    public static function review_count(int $packageid): int {
        global $DB;
        return $DB->count_records(self::OPS, ['packageid' => $packageid, 'source_table' => 'customcert_issues', 'status' => self::OP_REVIEW]);
    }

    /**
     * @param \stdClass $package
     * @param bool $backupconfirmed
     * @param string $scope etapa a executar
     * @return \stdClass job
     */
    public static function start(\stdClass $package, bool $backupconfirmed, string $scope = self::SCOPE_REGISTRATION): \stdClass {
        global $DB, $USER;
        if (!in_array($scope, self::SCOPES, true)) {
            throw new \coding_exception('Etapa desconhecida: ' . $scope);
        }
        if (!self::is_enabled()) {
            throw new \moodle_exception('error_recovery_disabled', 'local_progressrecovery');
        }
        if (!$backupconfirmed) {
            throw new \moodle_exception('error_recovery_backup', 'local_progressrecovery');
        }
        if ($package->status !== import_manager::STATUS_VALIDATED) {
            throw new \moodle_exception('error_package_changed', 'local_progressrecovery');
        }
        if (!hash_equals($package->package_hash, (string)hash_file('sha256', import_manager::package_path($package->package_uuid)))) {
            throw new \moodle_exception('error_package_changed', 'local_progressrecovery');
        }
        $compare = comparison_manager::latest_job((int)$package->id);
        if ($compare === null || $compare->status !== comparison_manager::STATUS_DONE) {
            throw new \moodle_exception('error_recovery_compare', 'local_progressrecovery');
        }
        if (self::is_running((int)$package->id)) {
            throw new \moodle_exception('error_recovery_running', 'local_progressrecovery');
        }
        // As contas e as fichas vêm primeiro: o resto do progresso depende delas.
        $position = array_search($scope, self::SCOPES, true);
        if ($position > 0 && !self::is_scope_done((int)$package->id, self::SCOPES[$position - 1])) {
            throw new \moodle_exception('error_recovery_order', 'local_progressrecovery');
        }
        $courses = self::scope_courses($compare, $scope);
        $compareparams = json_decode($compare->params, true);

        $total = 0;
        if ($scope === self::SCOPE_REGISTRATION) {
            $total = $DB->count_records(entity_map::TABLE, ['jobid' => $compare->id, 'entity' => 'user', 'status' => entity_map::MISSING, 'detail' => 'create']);
        }
        foreach (self::remaining((int)$package->id, $compare, $scope) as $classes) {
            $total += array_sum($classes);
        }
        // Cada emissão é gravada e depois verificada.
        if ($scope === self::SCOPE_CERTIFICATES) {
            $total = $total * 2 + self::review_count((int)$package->id);
        }
        $now = time();
        $job = (object)[
            'packageid' => $package->id,
            'jobtype' => self::JOBTYPE,
            'status' => comparison_manager::STATUS_RUNNING,
            'stage_state' => json_encode(['stage' => 0, 'line' => 0]),
            'params' => json_encode([
                'compare_job' => (int)$compare->id,
                'scope' => $scope,
                'courses' => $courses,
                'copy_epoch' => (int)$compareparams['copy_epoch'],
                'backup_confirmed' => true,
            ]),
            'summary' => null,
            'total_records' => $total,
            'processed_records' => 0,
            'userid' => (int)$USER->id,
            'timestarted' => $now,
            'timecompleted' => null,
            'timemodified' => $now,
        ];
        $job->id = $DB->insert_record(comparison_manager::JOBS, $job);
        audit_manager::log('recovery_started', audit_manager::RESULT_OK,
            ['job' => (int)$job->id, 'compare_job' => (int)$compare->id, 'scope' => $scope, 'courses' => $courses, 'backup_confirmed' => true], (int)$package->id);
        return $job;
    }

    /**
     * @param \stdClass $job
     * @param \stdClass $package
     */
    public function __construct(\stdClass $job, \stdClass $package) {
        $this->job = $job;
        $this->package = $package;
        $this->manifest = json_decode($package->manifest, true);
        $this->params = json_decode($job->params, true);
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
        $stages = self::STAGES[$this->params['scope']];
        $index = (int)$this->cursor['stage'];
        list($type, $table) = $stages[$index];
        $finished = true;
        $processed = 0;
        $read = 0;
        if ($type === 'accounts') {
            $processed = $this->stage_accounts();
        } else if ($type === 'cohorts') {
            $this->stage_cohorts();
        } else if ($type === 'summary') {
            $this->stage_summary();
        } else {
            $size = ['records' => self::BATCH, 'certificate_check' => self::CERTIFICATE_BATCH][$type] ?? self::GRADE_BATCH;
            $rows = $this->read_rows($table, $size);
            $read = count($rows);
            $finished = $read < $size;
            if ($rows) {
                $processed = $type === 'certificate_check' ? $this->process_certificate_checks($table, $rows) : $this->process_rows($table, $rows, $type);
            }
        }
        // O cursor só avança no fim do lote: repetir um lote é seguro, porque as operações feitas são saltadas.
        if ($finished) {
            $this->cursor = ['stage' => $index + 1, 'line' => 0];
        } else {
            $this->cursor['line'] += $read;
        }
        $this->job->stage_state = json_encode($this->cursor);
        $this->job->processed_records = min((int)$this->job->total_records, (int)$this->job->processed_records + $processed);
        $this->job->timemodified = time();
        if ($this->cursor['stage'] >= count($stages)) {
            $this->job->status = comparison_manager::STATUS_DONE;
            $this->job->timecompleted = time();
        }
        $DB->update_record(comparison_manager::JOBS, $this->job);
        if ($this->job->status === comparison_manager::STATUS_DONE) {
            audit_manager::log('recovery_finished', audit_manager::RESULT_OK, ['job' => (int)$this->job->id], (int)$this->package->id);
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

    private function all_rows(string $table): \Generator {
        foreach ($this->reader->lines(package_validator::TABLE_FILES[$table], 33554432) as $text) {
            if ($text !== false && $text !== '') {
                yield json_decode($text, true);
            }
        }
    }

    private function comparejob(): int {
        return (int)$this->params['compare_job'];
    }

    private function stage_accounts(): int {
        global $DB;
        $entries = $DB->get_records(entity_map::TABLE, ['jobid' => $this->comparejob(), 'entity' => 'user', 'status' => entity_map::MISSING, 'detail' => 'create'], '', 'source_id, id');
        if (!$entries) {
            return 0;
        }
        $processed = 0;
        foreach ($this->all_rows('user') as $row) {
            $sourceid = (int)$row['d']['id'];
            if (!isset($entries[$sourceid])) {
                continue;
            }
            $processed++;
            $this->create_account($row['d'], (int)$entries[$sourceid]->id);
        }
        return $processed;
    }

    private function create_account(array $d, int $entryid): void {
        global $DB;
        $sourceid = (int)$d['id'];
        $check = account_creator::check($d);
        try {
            $transaction = $DB->start_delegated_transaction();
            if ($check['reason'] === null) {
                $destid = account_creator::create($d);
                $DB->update_record(entity_map::TABLE, (object)['id' => $entryid, 'dest_id' => $destid, 'status' => entity_map::REMAPPED, 'detail' => 'created']);
                $this->save_op('user', $sourceid, self::OP_DONE, ['dest_table' => 'user', 'dest_id' => $destid, 'action' => 'create'], null, $d['username']);
            } else if ($check['reason'] === 'account_exists') {
                // Entretanto a pessoa entrou pelo MyZurich: usa-se a conta dela.
                $DB->update_record(entity_map::TABLE, (object)['id' => $entryid, 'dest_id' => $check['dest_id'], 'status' => entity_map::REMAPPED, 'detail' => 'account_exists']);
                $this->save_op('user', $sourceid, self::OP_SKIPPED, ['dest_table' => 'user', 'dest_id' => $check['dest_id'], 'action' => null], 'account_exists', $d['username']);
            } else {
                $DB->update_record(entity_map::TABLE, (object)['id' => $entryid, 'status' => $check['reason'] === 'email_exists' ? entity_map::MANUAL : entity_map::INVALID, 'detail' => $check['reason']]);
                $this->save_op('user', $sourceid, self::OP_SKIPPED, [], $check['reason'], $d['username']);
            }
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $this->failed('user', $sourceid, $e, $d['username']);
        }
    }

    private function process_rows(string $table, array $rows, string $type): int {
        global $DB;
        $ids = array_map(function ($row) {
            return (int)$row['sid'];
        }, $rows);
        list($in, $params) = $DB->get_in_or_equal($ids);
        $approved = $DB->get_records_select(comparison_manager::ITEMS, "jobid = ? AND source_table = ? AND source_recordid $in",
            array_merge([$this->comparejob(), $table], $params), '', 'source_recordid, classification, courseid');
        $scope = [];
        foreach ($rows as $row) {
            $item = $approved[(int)$row['sid']] ?? null;
            if ($item !== null && ($this->params['courses'] === null || in_array((int)$item->courseid, $this->params['courses'], true))
                    && in_array($item->classification, [conflict_detector::NEW, conflict_detector::CHANGED], true)) {
                $scope[] = $row;
            }
        }
        // A entidade pode ter mudado com as contas criadas neste mesmo trabalho: nunca se usa uma cache antiga.
        $map = new entity_map($this->comparejob());
        if ($type !== 'records') {
            $scope = $this->grades_of_type($scope, $type, $map);
        }
        if (!$scope) {
            return 0;
        }
        // Uma nota diferente da origem já foi tratada: repetir não a recalcula nem lhe tira a marca.
        $done = [];
        $scopeids = array_map(function ($row) {
            return (int)$row['sid'];
        }, $scope);
        list($in, $params) = $DB->get_in_or_equal($scopeids);
        foreach ($DB->get_records_select(self::OPS, "packageid = ? AND source_table = ? AND status IN (?, ?, ?, ?) AND source_recordid $in",
                array_merge([(int)$this->package->id, $table, self::OP_DONE, self::OP_DIFFERS, self::OP_REVIEW, self::OP_KEPT], $params), '', 'source_recordid') as $op) {
            $done[(int)$op->source_recordid] = true;
        }

        $period = ['start' => $this->manifest['period']['start_epoch'], 'end' => $this->manifest['period']['end_epoch_exclusive']];
        $detector = new conflict_detector($map, (int)$this->params['copy_epoch'], $period);
        $writer = new record_writer($map, (int)$this->package->id, $this->comparejob());
        if ($type === 'grades_course') {
            return $this->process_course_totals($table, $scope, $done, $approved, $writer);
        }
        $values = $table === 'feedback_completed' ? $this->feedback_values() : [];
        $extra = $table === 'feedback_completed' ? ['values' => $values] : [];
        if ($table === 'logstore_standard_log') {
            $extra['skip_existing'] = true;
        }
        if ($table === 'scorm_scoes_track') {
            if ($this->sourcekeys === null) {
                $this->sourcekeys = conflict_detector::scorm_source_keys($this->all_rows($table), $map);
            }
            $extra['sourcekeys'] = $this->sourcekeys;
        }
        $current = $detector->classify($table, $scope, $extra);
        $pending = [];
        foreach ($scope as $index => $row) {
            if (!isset($done[(int)$row['sid']])) {
                $pending[] = [$row, $current[$index]];
            }
        }
        if (in_array($table, self::BULK, true) && $pending) {
            try {
                $transaction = $DB->start_delegated_transaction();
                foreach ($pending as $entry) {
                    $this->apply($table, $entry[0], $entry[1], $writer, []);
                }
                $transaction->allow_commit();
                return count($pending);
            } catch (\Throwable $e) {
                // Nada deste lote ficou gravado: repete-se linha a linha, para isolar a que falha.
                $DB->force_transaction_rollback();
                error_log('local_progressrecovery: lote de ' . $table . ': ' . get_class($e) . ': ' . $e->getMessage());
            }
        }
        foreach ($pending as $entry) {
            $sid = (int)$entry[0]['sid'];
            try {
                $transaction = $DB->start_delegated_transaction();
                $this->apply($table, $entry[0], $entry[1], $writer, $values[(int)$entry[0]['d']['id']] ?? []);
                $transaction->allow_commit();
            } catch (\Throwable $e) {
                $this->failed($table, $sid, $e);
            }
        }
        return count($pending);
    }

    private function apply(string $table, array $row, array $current, record_writer $writer, array $values): void {
        $sid = (int)$row['sid'];
        $class = $current['classification'];
        $proceed = in_array($class, [conflict_detector::NEW, conflict_detector::CHANGED], true);
        if (!$proceed || $current['reason'] === 'user_to_create') {
            $this->save_op($table, $sid, self::OP_SKIPPED, ['dest_id' => $current['destination_recordid']], $proceed ? 'account_missing' : 'now_' . $class);
            return;
        }
        $result = $writer->write($table, $row['d'], $current['destination_recordid'], $values);
        $this->save_grade_aware_op($table, $sid, $result);
    }

    /**
     * Só as emissões gravadas por esta recuperação e ainda por confirmar. Um problema não anula a emissão: fica assinalado.
     */
    private function process_certificate_checks(string $table, array $rows): int {
        global $DB;
        $ids = array_map(function ($row) {
            return (int)$row['sid'];
        }, $rows);
        list($in, $params) = $DB->get_in_or_equal($ids);
        $ops = $DB->get_records_select(self::OPS, "packageid = ? AND source_table = ? AND status IN (?, ?) AND action = ? AND source_recordid $in",
            array_merge([(int)$this->package->id, $table, self::OP_DONE, self::OP_REVIEW, 'insert'], $params), '', 'source_recordid, id, dest_recordid, reason');
        $processed = 0;
        foreach ($ops as $op) {
            if ($op->reason === self::CHECK_OK) {
                continue;
            }
            $processed++;
            $problem = certificate_checker::check((int)$op->dest_recordid);
            $DB->update_record(self::OPS, (object)[
                'id' => $op->id,
                'status' => $problem === null ? self::OP_DONE : self::OP_REVIEW,
                'reason' => $problem === null ? self::CHECK_OK : $problem['reason'],
                'detail' => $problem === null ? null : $problem['detail'],
                'timemodified' => time(),
            ]);
        }
        return $processed;
    }

    private function save_grade_aware_op(string $table, int $sid, array $result): void {
        if (!empty($result['differs'])) {
            $this->save_op($table, $sid, self::OP_DIFFERS, $result, 'differs_from_source');
        } else {
            $this->save_op($table, $sid, self::OP_DONE, $result, null);
        }
    }

    /**
     * Só as linhas do tipo de nota desta fase: os totais dependem das notas das actividades.
     */
    private function grades_of_type(array $scope, string $type, entity_map $map): array {
        global $DB;
        $map->preload('grade_items', array_map(function ($row) {
            return (int)$row['d']['itemid'];
        }, $scope));
        $destids = [];
        foreach ($scope as $row) {
            $entry = $map->get('grade_items', (int)$row['d']['itemid']);
            if (entity_map::usable($entry)) {
                $destids[] = (int)$entry->dest_id;
            }
        }
        if (!$destids) {
            return [];
        }
        list($in, $params) = $DB->get_in_or_equal(array_values(array_unique($destids)));
        $items = $DB->get_records_select('grade_items', "id $in", $params, '', 'id, itemtype');
        $out = [];
        foreach ($scope as $row) {
            $entry = $map->get('grade_items', (int)$row['d']['itemid']);
            $itemtype = entity_map::usable($entry) ? ($items[(int)$entry->dest_id]->itemtype ?? null) : null;
            if (($itemtype === 'course') === ($type === 'grades_course')) {
                $out[] = $row;
            }
        }
        return $out;
    }

    /**
     * Os totais não voltam a ser classificados: já foram recalculados com as notas das actividades,
     * por isso a data é sempre recente. O cálculo do Totara respeita o que está bloqueado no destino.
     */
    private function process_course_totals(string $table, array $scope, array $done, array $approved, record_writer $writer): int {
        global $DB;
        $processed = 0;
        foreach ($scope as $row) {
            $sid = (int)$row['sid'];
            if (isset($done[$sid])) {
                continue;
            }
            $processed++;
            try {
                $transaction = $DB->start_delegated_transaction();
                $existed = $approved[$sid]->classification === conflict_detector::CHANGED;
                $this->save_grade_aware_op($table, $sid, $writer->course_total($row['d'], $existed));
                $transaction->allow_commit();
            } catch (\Throwable $e) {
                $this->failed($table, $sid, $e);
            }
        }
        return $processed;
    }

    /**
     * Uma falha fica registada com o tipo do erro, nunca com os dados, e os restantes registos continuam.
     */
    private function failed(string $table, int $sid, \Throwable $e, ?string $detail = null): void {
        global $DB;
        if ($DB->is_transaction_started()) {
            $DB->force_transaction_rollback();
        }
        error_log('local_progressrecovery: ' . $table . ' ' . $sid . ': ' . get_class($e) . ': ' . $e->getMessage());
        $code = $e instanceof \moodle_exception ? $e->errorcode : get_class($e);
        $this->save_op($table, $sid, self::OP_FAILED, [], (string)$code, $detail);
    }

    private function save_op(string $table, int $sid, string $status, array $result, ?string $reason, ?string $detail = null): void {
        global $DB;
        $previous = $result['previous'] ?? null;
        $op = (object)[
            'packageid' => (int)$this->package->id,
            'jobid' => (int)$this->job->id,
            'source_table' => $table,
            'source_recordid' => $sid,
            'dest_table' => $result['dest_table'] ?? null,
            'dest_recordid' => $result['dest_id'] ?? null,
            'action' => $result['action'] ?? null,
            'status' => $status,
            'reason' => $reason === null ? null : substr($reason, 0, 40),
            'detail' => $detail,
            'previous' => $previous === null ? null : json_encode($previous),
            'timemodified' => time(),
        ];
        $existing = $DB->get_record(self::OPS, ['packageid' => $op->packageid, 'source_table' => $table, 'source_recordid' => $sid], 'id');
        if ($existing) {
            $op->id = $existing->id;
            $DB->update_record(self::OPS, $op);
        } else {
            $DB->insert_record(self::OPS, $op);
        }
    }

    private function feedback_values(): array {
        if ($this->values === null) {
            $this->values = [];
            foreach ($this->all_rows('feedback_value') as $row) {
                $this->values[(int)$row['d']['completed']][] = $row['d'];
            }
        }
        return $this->values;
    }

    private function stage_cohorts(): void {
        global $DB;
        $jobid = (int)$this->job->id;
        $courses = [];
        $changed = $DB->count_records(self::OPS, ['jobid' => $jobid, 'source_table' => 'user', 'status' => self::OP_DONE]) > 0;
        $rows = $DB->get_records_sql("SELECT DISTINCT i.courseid
                                        FROM {" . self::OPS . "} o
                                        JOIN {" . comparison_manager::ITEMS . "} i ON i.jobid = ? AND i.source_table = o.source_table AND i.source_recordid = o.source_recordid
                                       WHERE o.jobid = ? AND o.source_table = ? AND o.status = ?", [$this->comparejob(), $jobid, 'course_completions', self::OP_DONE]);
        foreach ($rows as $row) {
            $courses[] = (int)$row->courseid;
        }
        if ($changed || $courses) {
            $queued = cohort_sync::queue(cohort_sync::affected($courses, $this->comparejob()));
        } else {
            // Nada de novo para sincronizar: os membros de referência continuam a ser os da execução anterior.
            $queued = [];
            $previous = $DB->get_records_select(comparison_manager::JOBS, 'packageid = ? AND jobtype = ? AND id < ?',
                [(int)$this->package->id, self::JOBTYPE, $jobid], 'id DESC', 'id, params', 0, 1);
            if ($previous) {
                $queued = json_decode(reset($previous)->params, true)['cohorts'] ?? [];
            }
        }
        $this->params['cohorts'] = $queued;
        $this->job->params = json_encode($this->params);
    }

    /**
     * O resultado é o acumulado do pacote: uma operação feita numa execução anterior continua a contar.
     */
    private function stage_summary(): void {
        global $DB;
        $counts = [];
        $rows = $DB->get_recordset_sql("SELECT source_table, status, action, COUNT(*) AS n FROM {" . self::OPS . "}
                                         WHERE packageid = ? GROUP BY source_table, status, action", [(int)$this->package->id]);
        foreach ($rows as $row) {
            $key = $row->status === self::OP_DONE ? (string)$row->action : $row->status;
            $counts[$row->source_table][$key] = ($counts[$row->source_table][$key] ?? 0) + (int)$row->n;
        }
        $rows->close();
        $this->job->summary = json_encode(['tables' => $counts]);
    }
}
