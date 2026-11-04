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

use core_completion\helper;
use local_progressrecovery\comparison\comparison_manager;
use local_progressrecovery\comparison\conflict_detector;
use local_progressrecovery\comparison\entity_map;

defined('MOODLE_INTERNAL') || die();

/**
 * Escreve um registo da origem no destino, já com os ids traduzidos.
 *
 * As conclusões usam a API core_completion\helper, que valida os estados, grava o registo de conclusão
 * e limpa as caches. O Feedback não tem API de importação: a escrita directa repete o que
 * feedback_save_tmp_values() faz, depois de a comparação ter validado o inquérito e as perguntas.
 * O SCORM também é escrito directamente: scorm_insert_track() grava a data de hoje e dispara logo as
 * notas e a conclusão, o que apagaria as datas da origem. As notas nunca são copiadas: o Totara calcula-as
 * a partir das tentativas já recuperadas, e o resultado é comparado com a origem. Os logs são escritos
 * directamente, porque a API dos eventos grava sempre a data de hoje.
 *
 * Quem chama abre a transacção de cada registo.
 *
 * @package    local_progressrecovery
 * @copyright  2026 IT EDUdigital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class record_writer {
    /** @var entity_map */
    private $map;
    /** @var string descrição gravada no registo de conclusão do Totara */
    private $message;
    /** @var array pares (curso, utilizador) com as linhas de nota já reservadas */
    private $reserved = [];
    /** @var int */
    private $packageid;
    /** @var int */
    private $comparejob;
    /** @var array contextos do destino já lidos */
    private $contexts = [];
    /** @var array actividades do destino já lidas */
    private $modules = [];
    /** @var array registos de progresso já traduzidos */
    private $progress = [];

    /** Objectos de log que têm tradução na comparação. */
    private const LOG_ENTITIES = ['user', 'course', 'scorm', 'scorm_scoes', 'feedback', 'customcert'];
    /** Objectos de log que são registos de progresso. Criados depois da cópia, o mesmo id é de outro registo no destino. */
    private const LOG_PROGRESS = ['grade_grades', 'course_modules_completion', 'course_completions', 'feedback_completed'];

    /**
     * @param entity_map $map
     * @param int $packageid
     * @param int $comparejob comparação usada nesta recuperação
     */
    public function __construct(entity_map $map, int $packageid, int $comparejob) {
        $this->map = $map;
        $this->packageid = $packageid;
        $this->comparejob = $comparejob;
        $this->message = 'Recovered by local_progressrecovery from package ' . $packageid;
    }

    /**
     * @param string $table tabela da origem
     * @param array $d linha do pacote
     * @param int|null $destid registo a actualizar, se existir
     * @param array $values feedback_value da resposta, só para o Feedback
     * @return array dest_table, dest_id, action, previous
     */
    public function write(string $table, array $d, ?int $destid, array $values = []): array {
        switch ($table) {
            case 'feedback_completed':
                return $this->feedback($d, $destid, $values);
            case 'course_modules_completion':
                return $this->activity_completion($d, $destid);
            case 'course_completion_crit_compl':
                return $this->criteria_completion($d, $destid);
            case 'course_completions':
                return $this->course_completion($d, $destid);
            case 'scorm_scoes_track':
                return $this->scorm_track($d, $destid);
            case 'grade_grades':
                return $this->activity_grade($d, $destid);
            case 'logstore_standard_log':
                return $this->log_event($d);
            case 'customcert_issues':
                return $this->certificate_issue($d);
        }
        throw new \coding_exception('Tabela sem escrita: ' . $table);
    }

    private function dest(string $entity, $sourceid): int {
        return (int)$this->map->get($entity, (int)$sourceid)->dest_id;
    }

    private function feedback(array $d, ?int $destid, array $values): array {
        global $DB;
        $feedback = $this->dest('feedback', $d['feedback']);
        $userid = $this->dest('user', $d['userid']);
        $previous = null;
        if ($destid !== null) {
            $record = $DB->get_record('feedback_completed', ['id' => $destid], '*', MUST_EXIST);
            if ((int)$record->feedback !== $feedback || (int)$record->userid !== $userid) {
                throw new \moodle_exception('error_recovery_target', 'local_progressrecovery');
            }
            // Nunca se guardam as respostas anteriores: a reposição destas faz-se pelo backup.
            $previous = ['timemodified' => (int)$record->timemodified];
            $DB->delete_records('feedback_value', ['completed' => $destid]);
            $record->timemodified = (int)$d['timemodified'];
            $DB->update_record('feedback_completed', $record);
        } else {
            $destid = (int)$DB->insert_record('feedback_completed', (object)[
                'feedback' => $feedback,
                'userid' => $userid,
                'timemodified' => (int)$d['timemodified'],
                'random_response' => (int)$d['random_response'],
                'anonymous_response' => (int)$d['anonymous_response'],
                'courseid' => (int)$d['courseid'],
            ]);
        }
        foreach ($values as $value) {
            $DB->insert_record('feedback_value', (object)[
                'course_id' => (int)$value['course_id'],
                'item' => $this->dest('feedback_item', $value['item']),
                'completed' => $destid,
                'tmp_completed' => 0,
                'value' => (string)$value['value'],
            ]);
        }
        return self::result('feedback_completed', $destid, $previous);
    }

    private function activity_completion(array $d, ?int $destid): array {
        global $DB;
        $record = (object)[
            'coursemoduleid' => $this->dest('course_modules', $d['coursemoduleid']),
            'userid' => $this->dest('user', $d['userid']),
            'completionstate' => (int)$d['completionstate'],
            'progress' => $d['progress'],
            'viewed' => $d['viewed'],
            'timemodified' => (int)$d['timemodified'],
            'timecompleted' => $d['timecompleted'],
            'reaggregate' => (int)$d['reaggregate'],
        ];
        $previous = null;
        if ($destid !== null) {
            $previous = (array)$DB->get_record('course_modules_completion', ['id' => $destid], '*', MUST_EXIST);
            $record->id = $destid;
        }
        if (helper::get_module_completion_errors($record)) {
            throw new \moodle_exception('error_recovery_state', 'local_progressrecovery');
        }
        return self::result('course_modules_completion', (int)helper::write_module_completion($record, $this->message), $previous);
    }

    private function criteria_completion(array $d, ?int $destid): array {
        global $DB;
        $record = (object)[
            'userid' => $this->dest('user', $d['userid']),
            'course' => $this->dest('course', $d['course']),
            'criteriaid' => $this->dest('course_completion_criteria', $d['criteriaid']),
            'gradefinal' => $d['gradefinal'],
            'unenroled' => $d['unenroled'],
            'rpl' => $d['rpl'],
            'timecompleted' => $d['timecompleted'],
        ];
        $previous = null;
        if ($destid !== null) {
            $previous = (array)$DB->get_record('course_completion_crit_compl', ['id' => $destid], '*', MUST_EXIST);
            $record->id = $destid;
        }
        return self::result('course_completion_crit_compl', (int)helper::write_criteria_completion($record, $this->message, false), $previous);
    }

    private function course_completion(array $d, ?int $destid): array {
        global $DB;
        $complete = (int)$d['timecompleted'] > 0;
        $previous = null;
        if ($destid !== null) {
            // A inscrição, a posição e a organização continuam a ser as do destino.
            $record = $DB->get_record('course_completions', ['id' => $destid], '*', MUST_EXIST);
            $previous = (array)$record;
            if ((int)$d['timestarted'] > 0) {
                $record->timestarted = (int)$d['timestarted'];
            }
        } else {
            $record = (object)[
                'userid' => $this->dest('user', $d['userid']),
                'course' => $this->dest('course', $d['course']),
                'organisationid' => null,
                'positionid' => null,
                'timeenrolled' => (int)$d['timeenrolled'],
                'timestarted' => (int)$d['timestarted'],
                'reaggregate' => (int)$d['reaggregate'],
                'invalidatecache' => 0,
                'renewalstatus' => (int)$d['renewalstatus'],
                'duedate' => $d['duedate'],
            ];
        }
        $record->timecompleted = $d['timecompleted'];
        $record->status = (int)$d['status'];
        $record->rpl = $d['rpl'];
        $record->rplgrade = $d['rplgrade'];
        if ($complete) {
            $record->reaggregate = 0;
        }
        $id = helper::write_course_completion($record, $this->message);
        if ($id === false) {
            throw new \moodle_exception('error_recovery_state', 'local_progressrecovery');
        }
        return self::result('course_completions', (int)$id, $previous);
    }

    private function scorm_track(array $d, ?int $destid): array {
        global $DB;
        if ($destid === null) {
            $id = (int)$DB->insert_record('scorm_scoes_track', (object)[
                'userid' => $this->dest('user', $d['userid']),
                'scormid' => $this->dest('scorm', $d['scormid']),
                'scoid' => $this->dest('scorm_scoes', $d['scoid']),
                'attempt' => (int)$d['attempt'],
                'element' => (string)$d['element'],
                'value' => (string)$d['value'],
                'timemodified' => (int)$d['timemodified'],
            ]);
            return self::result('scorm_scoes_track', $id, null);
        }
        $record = $DB->get_record('scorm_scoes_track', ['id' => $destid], 'id, timemodified', MUST_EXIST);
        // Como em scorm_insert_track(), o início de uma tentativa nunca é alterado.
        if ($d['element'] === 'x.start.time') {
            return ['dest_table' => 'scorm_scoes_track', 'dest_id' => $destid, 'action' => 'keep', 'previous' => null];
        }
        // Nunca se guarda o valor anterior, que é conteúdo SCORM: a reposição destas linhas faz-se pela cópia de segurança.
        $DB->update_record('scorm_scoes_track', (object)['id' => $destid, 'value' => (string)$d['value'], 'timemodified' => (int)$d['timemodified']]);
        return self::result('scorm_scoes_track', $destid, ['timemodified' => (int)$record->timemodified]);
    }

    /**
     * Nota de um SCORM, com as datas da origem. Usa scorm_grade_item_update() e não scorm_update_grades():
     * esta última chama também scorm_set_completion(), e a conclusão já está recuperada.
     */
    private function activity_grade(array $d, ?int $destid): array {
        global $DB;
        $item = $DB->get_record('grade_items', ['id' => $this->dest('grade_items', $d['itemid'])], '*', MUST_EXIST);
        if ($item->itemtype !== 'mod' || $item->itemmodule !== 'scorm') {
            throw new \moodle_exception('error_recovery_grade_type', 'local_progressrecovery');
        }
        $userid = $this->dest('user', $d['userid']);
        $previous = null;
        if ($destid !== null) {
            $record = $DB->get_record('grade_grades', ['id' => $destid], 'rawgrade, finalgrade, usermodified, timecreated, timemodified', MUST_EXIST);
            // Uma linha reservada num lote anterior não é uma nota: esta é uma nota nova.
            if ($record->rawgrade !== null || $record->finalgrade !== null || $record->timemodified !== null) {
                $previous = (array)$record;
            }
        }
        $this->reserve_grade_rows((int)$item->courseid, $userid);
        $scorm = $DB->get_record('scorm', ['id' => $item->iteminstance], '*', MUST_EXIST);
        $grades = scorm_get_user_grades($scorm, $userid);
        if (!$grades) {
            throw new \moodle_exception('error_recovery_no_attempt', 'local_progressrecovery');
        }
        $grades[$userid]->usermodified = $userid;
        $grades[$userid]->dategraded = $d['timemodified'] === null ? null : (int)$d['timemodified'];
        $grades[$userid]->datesubmitted = $d['timecreated'] === null ? null : (int)$d['timecreated'];
        if (scorm_grade_item_update($scorm, $grades) !== GRADE_UPDATE_OK) {
            throw new \moodle_exception('error_recovery_regrade', 'local_progressrecovery');
        }
        return self::grade_result((int)$item->id, $userid, $d, $previous, $previous === null ? 'insert' : 'update');
    }

    /**
     * Total do curso: resulta sempre do cálculo do Totara, que respeita as notas bloqueadas e alteradas à mão.
     *
     * @param array $d linha do pacote
     * @param bool $existed se a comparação encontrou o total no destino
     * @return array dest_table, dest_id, action, previous, differs
     */
    public function course_total(array $d, bool $existed): array {
        global $DB;
        $item = $DB->get_record('grade_items', ['id' => $this->dest('grade_items', $d['itemid'])], 'id, courseid', MUST_EXIST);
        $userid = $this->dest('user', $d['userid']);
        $this->reserve_grade_rows((int)$item->courseid, $userid);
        // Para um só utilizador, o Totara exige o item actualizado. O total é sempre recalculado.
        if (grade_regrade_final_grades((int)$item->courseid, $userid, \grade_item::fetch_course_item((int)$item->courseid)) !== true) {
            throw new \moodle_exception('error_recovery_regrade', 'local_progressrecovery');
        }
        return self::grade_result((int)$item->id, $userid, $d, null, $existed ? 'update' : 'insert');
    }

    /**
     * Cria as linhas de nota em falta do utilizador neste curso, sem notificar a conclusão.
     *
     * Ao recalcular o total, grade_category::aggregate_grades() cria estas linhas com grade_grade::insert(), que
     * chama notify_changed(). Numa actividade concluída por nota, a nota vazia devolve-a a incompleta com a data
     * de hoje, e dispara os eventos e as notificações de conclusão. Com a linha já criada, nada disso acontece.
     */
    private function reserve_grade_rows(int $courseid, int $userid): void {
        global $DB;
        $key = $courseid . '|' . $userid;
        if (isset($this->reserved[$key])) {
            return;
        }
        $this->reserved[$key] = true;
        $missing = $DB->get_records_sql("SELECT gi.id, gi.grademin, gi.grademax
                                           FROM {grade_items} gi
                                      LEFT JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.userid = ?
                                          WHERE gi.courseid = ? AND gi.itemtype <> ? AND gg.id IS NULL", [$userid, $courseid, 'course']);
        foreach ($missing as $item) {
            $DB->insert_record('grade_grades', (object)['itemid' => (int)$item->id, 'userid' => $userid, 'rawgrademin' => $item->grademin, 'rawgrademax' => $item->grademax,
                'hidden' => 0, 'locked' => 0, 'locktime' => 0, 'exported' => 0, 'overridden' => 0, 'excluded' => 0]);
        }
    }

    /**
     * Emissão da origem, com o código e a data originais: os PDF já descarregados continuam válidos. O módulo não
     * tem API para isto, e o view.php também insere directamente. Sem índice único, uma emissão duplicada impede
     * o PDF de abrir, por isso confirma-se outra vez mesmo antes de gravar.
     */
    private function certificate_issue(array $d): array {
        global $DB;
        $userid = $this->dest('user', $d['userid']);
        $customcertid = $this->dest('customcert', $d['customcertid']);
        if ($DB->record_exists('customcert_issues', ['userid' => $userid, 'customcertid' => $customcertid])
                || $DB->record_exists('customcert_issues', ['code' => $d['code']])) {
            throw new \moodle_exception('error_recovery_issue_exists', 'local_progressrecovery');
        }
        $id = $DB->insert_record('customcert_issues', (object)[
            'userid' => $userid,
            'customcertid' => $customcertid,
            'code' => $d['code'],
            'emailed' => (int)$d['emailed'],
            'timecreated' => (int)$d['timecreated'],
        ]);
        return self::result('customcert_issues', (int)$id, null);
    }

    /**
     * Evento da origem, com a data original e os ids do destino. O campo other fica como está: é um registo
     * histórico, serializado de formas diferentes por cada evento, e não entra no tempo no curso.
     */
    private function log_event(array $d): array {
        global $DB;
        $context = $this->log_context($d);
        $courseid = (int)($d['courseid'] ?? 0);
        $record = (object)[
            'eventname' => $d['eventname'],
            'component' => $d['component'],
            'action' => $d['action'],
            'target' => $d['target'],
            'objecttable' => $d['objecttable'],
            'objectid' => $this->log_object($d, $context),
            'crud' => $d['crud'],
            'edulevel' => (int)$d['edulevel'],
            'contextid' => (int)$context->id,
            'contextlevel' => (int)$context->contextlevel,
            'contextinstanceid' => (int)$context->instanceid,
            'userid' => $this->dest('user', $d['userid']),
            'courseid' => $courseid > 0 ? $this->dest('course', $courseid) : $d['courseid'],
            'relateduserid' => $this->log_user($d['relateduserid']),
            'anonymous' => (int)$d['anonymous'],
            'other' => $d['other'],
            'timecreated' => (int)$d['timecreated'],
            'origin' => $d['origin'],
            'ip' => $d['ip'],
            'realuserid' => $this->log_user($d['realuserid']),
        ];
        return self::result('logstore_standard_log', (int)$DB->insert_record('logstore_standard_log', $record), null);
    }

    /**
     * O contexto de um utilizador procura-se pela conta, porque a conta pode ter sido criada depois da comparação.
     * Os outros já foram validados pela comparação. O nível e a instância vêm sempre do destino.
     */
    private function log_context(array $d): \stdClass {
        global $DB;
        $source = (int)$d['contextid'];
        if (isset($this->contexts[$source])) {
            return $this->contexts[$source];
        }
        $context = false;
        if ((int)$d['contextlevel'] === 30) {
            $owner = $this->map->get('user', (int)$d['contextinstanceid']);
            if (entity_map::usable($owner)) {
                $context = $DB->get_record('context', ['contextlevel' => 30, 'instanceid' => (int)$owner->dest_id], 'id, contextlevel, instanceid');
            }
        } else {
            $entry = $this->map->get('context', $source);
            if (entity_map::usable($entry)) {
                $context = $DB->get_record('context', ['id' => (int)$entry->dest_id], 'id, contextlevel, instanceid');
            }
        }
        if (!$context) {
            throw new \moodle_exception('error_recovery_log_context', 'local_progressrecovery');
        }
        return $this->contexts[$source] = $context;
    }

    /**
     * @param mixed $sourceid
     * @return int|null a conta no destino. Sem correspondência fica vazio: o mesmo id pode ser de outra pessoa.
     */
    private function log_user($sourceid): ?int {
        if ($sourceid === null || (int)$sourceid <= 0) {
            return null;
        }
        $entry = $this->map->get('user', (int)$sourceid);
        return entity_map::usable($entry) ? (int)$entry->dest_id : null;
    }

    /**
     * Só fica um objectid que se sabe ser o mesmo registo. Os outros ficam vazios, para nunca apontar para um
     * registo alheio. O tempo no curso conta-se pelo utilizador, pelo curso e pela data, e não usa este campo.
     */
    private function log_object(array $d, \stdClass $context): ?int {
        global $DB;
        $table = $d['objecttable'];
        if ($table === null || $d['objectid'] === null) {
            return null;
        }
        $id = (int)$d['objectid'];
        // Os eventos scoreraw e status do SCORM declaram esta tabela, mas gravam o id do SCORM (mod/scorm/locallib.php).
        if ($table === 'scorm_scoes_track') {
            $entry = $this->map->get('scorm', $id);
            return entity_map::usable($entry) ? (int)$entry->dest_id : null;
        }
        if (in_array($table, self::LOG_PROGRESS, true)) {
            return $this->progress_id($table, $id);
        }
        if (in_array($table, self::LOG_ENTITIES, true)) {
            $entry = $this->map->get($table, $id);
            if (entity_map::usable($entry)) {
                return (int)$entry->dest_id;
            }
        }
        // A instância da própria actividade do evento, confirmada no destino.
        if ((int)$context->contextlevel === 70) {
            $cm = (int)$context->instanceid;
            if (!array_key_exists($cm, $this->modules)) {
                $this->modules[$cm] = $DB->get_record_sql('SELECT cm.id, cm.instance, m.name FROM {course_modules} cm JOIN {modules} m ON m.id = cm.module WHERE cm.id = ?', [$cm]);
            }
            $module = $this->modules[$cm];
            if ($module && $module->name === $table && (int)$module->instance === $id) {
                return $id;
            }
        }
        return null;
    }

    /**
     * @return int|null o registo do destino que corresponde a um registo de progresso da origem
     */
    private function progress_id(string $table, int $sourceid): ?int {
        global $DB;
        $key = $table . '|' . $sourceid;
        if (!array_key_exists($key, $this->progress)) {
            $op = $DB->get_record_select(recovery_manager::OPS, 'packageid = ? AND source_table = ? AND source_recordid = ? AND status <> ?',
                [$this->packageid, $table, $sourceid, recovery_manager::OP_FAILED], 'id, dest_recordid');
            $destid = $op ? $op->dest_recordid : null;
            if ($destid === null) {
                $item = $DB->get_record_select(comparison_manager::ITEMS, 'jobid = ? AND source_table = ? AND source_recordid = ? AND classification = ?',
                    [$this->comparejob, $table, $sourceid, conflict_detector::IDENTICAL], 'id, destination_recordid', IGNORE_MULTIPLE);
                $destid = $item ? $item->destination_recordid : null;
            }
            $this->progress[$key] = $destid === null ? null : (int)$destid;
        }
        return $this->progress[$key];
    }

    /**
     * A nota calculada tem de coincidir com a da origem. Se não coincidir, fica a do Totara e o caso é assinalado.
     */
    private static function grade_result(int $itemid, int $userid, array $d, ?array $previous, string $action): array {
        global $DB;
        $record = $DB->get_record('grade_grades', ['itemid' => $itemid, 'userid' => $userid], 'id, rawgrade, finalgrade');
        $same = $record && conflict_detector::same($d['rawgrade'], $record->rawgrade) && conflict_detector::same($d['finalgrade'], $record->finalgrade);
        return ['dest_table' => 'grade_grades', 'dest_id' => $record ? (int)$record->id : null, 'action' => $action, 'previous' => $previous, 'differs' => !$same];
    }

    private static function result(string $table, int $id, ?array $previous): array {
        return ['dest_table' => $table, 'dest_id' => $id, 'action' => $previous === null ? 'insert' : 'update', 'previous' => $previous];
    }
}
