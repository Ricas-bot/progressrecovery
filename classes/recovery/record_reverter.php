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
use local_progressrecovery\comparison\conflict_detector;

defined('MOODLE_INTERNAL') || die();

/**
 * Desfaz uma operação de recuperação, só se o registo ainda estiver como a recuperação o deixou. O que mudou
 * entretanto é trabalho novo do destino: fica como está e é listado.
 *
 * As conclusões usam a API core_completion\helper, que grava o registo de conclusão e limpa as caches. As notas de
 * actividade são repostas directamente: a API notificaria a conclusão, que perderia a data (secção 10.4 da análise).
 * Os totais são recalculados pelo Totara. Quem chama abre a transacção.
 *
 * @package    local_progressrecovery
 * @copyright  2026 IT EDUdigital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class record_reverter {
    public const CHANGED_SINCE = 'changed_since';
    public const NO_PREVIOUS = 'no_previous';
    public const FAILED = 'revert_failed';

    /** @var string descrição gravada no registo de conclusão do Totara */
    private $message;

    /**
     * @param int $packageid
     */
    public function __construct(int $packageid) {
        $this->message = 'Reverted by local_progressrecovery from package ' . $packageid;
    }

    /**
     * @param string $table tabela da origem
     * @param string $type fase da etapa
     * @param array $d linha do pacote
     * @param \stdClass $op operação gravada na recuperação
     * @return string|null motivo para não desfazer; null se foi desfeita
     */
    public function revert(string $table, string $type, array $d, \stdClass $op): ?string {
        global $DB;
        $previous = $op->previous === null ? null : json_decode($op->previous, true);
        $record = $DB->get_record($table, ['id' => (int)$op->dest_recordid]);
        if (!$record) {
            return self::CHANGED_SINCE;
        }
        switch ($table) {
            case 'customcert_issues':
                if ((string)$record->code !== (string)$d['code']) {
                    return self::CHANGED_SINCE;
                }
                $DB->delete_records($table, ['id' => (int)$record->id]);
                return null;
            case 'logstore_standard_log':
                if ($record->eventname !== $d['eventname'] || (int)$record->timecreated !== (int)$d['timecreated']) {
                    return self::CHANGED_SINCE;
                }
                $DB->delete_records($table, ['id' => (int)$record->id]);
                return null;
            case 'grade_grades':
                return $this->grade($record, $d, $previous, $type);
            case 'course_completions':
                return $this->course_completion($record, $d, $previous);
            case 'course_completion_crit_compl':
                return $this->criteria_completion($record, $d, $previous);
            case 'course_modules_completion':
                return $this->activity_completion($record, $d, $previous);
            case 'feedback_completed':
                return $this->feedback($record, $d, $op);
            case 'scorm_scoes_track':
                return $this->scorm_track($record, $d, $op);
        }
        throw new \coding_exception('Tabela sem reversão: ' . $table);
    }

    /**
     * Uma linha vazia é o estado de quem não tem nota, e é igual à que o Totara cria. Por isso a nota criada
     * é limpa e não apagada: a linha pode já existir antes da recuperação. O total é sempre recalculado.
     */
    private function grade(\stdClass $record, array $d, ?array $previous, string $type): ?string {
        global $DB;
        $item = $DB->get_record('grade_items', ['id' => (int)$record->itemid], 'id, courseid, itemtype', MUST_EXIST);
        if ($item->itemtype === 'course') {
            if (grade_regrade_final_grades((int)$item->courseid, (int)$record->userid, \grade_item::fetch_course_item((int)$item->courseid)) !== true) {
                throw new \moodle_exception('error_recovery_regrade', 'local_progressrecovery');
            }
            return null;
        }
        if ((int)$record->timemodified !== (int)$d['timemodified'] || (int)$record->overridden > 0) {
            return self::CHANGED_SINCE;
        }
        $values = $previous ?? ['rawgrade' => null, 'finalgrade' => null, 'usermodified' => null, 'timecreated' => null, 'timemodified' => null];
        $DB->update_record('grade_grades', (object)(['id' => (int)$record->id] + $values));
        return null;
    }

    private function course_completion(\stdClass $record, array $d, ?array $previous): ?string {
        if ((int)$record->status !== (int)$d['status'] || (int)$record->timecompleted !== (int)$d['timecompleted']) {
            return self::CHANGED_SINCE;
        }
        if ($previous === null) {
            helper::delete_course_completion((int)$record->course, (int)$record->userid, $this->message);
        } else if (helper::write_course_completion((object)$previous, $this->message) === false) {
            throw new \moodle_exception('error_recovery_state', 'local_progressrecovery');
        }
        return null;
    }

    private function criteria_completion(\stdClass $record, array $d, ?array $previous): ?string {
        if ((int)$record->timecompleted !== (int)$d['timecompleted']) {
            return self::CHANGED_SINCE;
        }
        if ($previous === null) {
            helper::delete_criteria_completion((int)$record->id, $this->message);
        } else {
            helper::write_criteria_completion((object)$previous, $this->message, false);
        }
        return null;
    }

    private function activity_completion(\stdClass $record, array $d, ?array $previous): ?string {
        if ((int)$record->completionstate !== (int)$d['completionstate'] || (int)$record->timemodified !== (int)$d['timemodified']) {
            return self::CHANGED_SINCE;
        }
        if ($previous === null) {
            helper::delete_module_completion((int)$record->id, $this->message);
        } else {
            helper::write_module_completion((object)$previous, $this->message);
        }
        return null;
    }

    /**
     * As respostas anteriores nunca são guardadas, por isso uma resposta substituída não pode ser reposta aqui.
     */
    private function feedback(\stdClass $record, array $d, \stdClass $op): ?string {
        global $DB;
        if ($op->action !== 'insert') {
            return self::NO_PREVIOUS;
        }
        if ((int)$record->timemodified !== (int)$d['timemodified']) {
            return self::CHANGED_SINCE;
        }
        $DB->delete_records('feedback_value', ['completed' => (int)$record->id]);
        $DB->delete_records('feedback_completed', ['id' => (int)$record->id]);
        return null;
    }

    /**
     * O valor anterior nunca é guardado, por isso uma linha actualizada não pode ser reposta aqui.
     */
    private function scorm_track(\stdClass $record, array $d, \stdClass $op): ?string {
        global $DB;
        if ($op->action !== 'insert') {
            return self::NO_PREVIOUS;
        }
        if ((string)$record->value !== (string)$d['value'] || (int)$record->timemodified !== (int)$d['timemodified']) {
            return self::CHANGED_SINCE;
        }
        $DB->delete_records('scorm_scoes_track', ['id' => (int)$record->id]);
        return null;
    }
}
