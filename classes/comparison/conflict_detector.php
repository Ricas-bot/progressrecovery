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

defined('MOODLE_INTERNAL') || die();

/**
 * Classifica cada registo da origem face ao destino. Só lê as tabelas do Totara.
 *
 * Regra do cliente: se o destino já tem dados novos para a mesma unidade (utilizador + actividade),
 * mantêm-se os do destino e a origem só preenche o que falta. "Novo" é tudo o que tem data igual ou
 * posterior ao instante da cópia e não é idêntico à origem.
 *
 * @package    local_progressrecovery
 * @copyright  2026 IT EDUdigital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class conflict_detector {
    public const NEW = 'new';
    public const IDENTICAL = 'identical';
    public const CHANGED = 'changed';
    public const CONFLICT = 'conflict';
    public const INVALID = 'invalid';
    public const DEST_NEWER = 'dest_newer';
    public const IGNORED = 'ignored';
    public const PENDING_ENROL = 'pending_enrol';

    /**
     * O Totara calcula a conclusão e a nota de um SCORM a partir das tentativas do destino, e volta a calculá-las sempre
     * que a nota muda (completion_info::inform_grade_changed). Por isso seguem a tentativa que fica no destino (P23).
     */
    private const FOLLOW_SCORM_ATTEMPT = ['course_modules_completion', 'grade_grades'];

    /** Tabelas em que a linha se identifica por utilizador + chave. */
    private const KEYED = [
        'course_modules_completion' => [
            'component' => 'activity_completion',
            'keys' => ['coursemoduleid' => 'course_modules'],
            'compare' => ['completionstate', 'progress', 'viewed', 'timemodified', 'timecompleted'],
            'newer' => ['timemodified', 'timecompleted'],
        ],
        'course_completions' => [
            'component' => 'course_completion',
            'keys' => ['course' => 'course'],
            'compare' => ['timestarted', 'timecompleted', 'status'],
            'newer' => ['timestarted', 'timecompleted'],
        ],
        'course_completion_crit_compl' => [
            'component' => 'course_completion',
            'keys' => ['course' => 'course', 'criteriaid' => 'course_completion_criteria'],
            'compare' => ['gradefinal', 'unenroled', 'rpl', 'timecompleted'],
            'newer' => ['timecompleted'],
        ],
        'course_completion_history' => [
            'component' => 'course_completion',
            'keys' => ['courseid' => 'course'],
            'plainkeys' => ['timecompleted'],
            'compare' => ['grade', 'grademax', 'grademin'],
            'newer' => [],
        ],
        'grade_grades' => [
            'component' => 'grades',
            'keys' => ['itemid' => 'grade_items'],
            'compare' => ['rawgrade', 'finalgrade', 'hidden', 'locked', 'overridden', 'excluded', 'feedback'],
            'newer' => ['timemodified'],
        ],
    ];

    /** @var entity_map */
    private $map;
    /** @var int instante da cópia */
    private $copytime;
    /** @var array */
    private $period;

    /**
     * @param entity_map $map
     * @param int $copytime
     * @param array $period início e fim (exclusivo) do período do pacote
     */
    public function __construct(entity_map $map, int $copytime, array $period) {
        $this->map = $map;
        $this->copytime = $copytime;
        $this->period = $period;
    }

    /**
     * @param string $table
     * @return string|null componente de uma tabela de progresso
     */
    public static function component_of(string $table): ?string {
        $others = ['scorm_scoes_track' => 'scorm', 'feedback_completed' => 'feedback', 'customcert_issues' => 'customcert', 'logstore_standard_log' => 'logs'];
        return self::KEYED[$table]['component'] ?? $others[$table] ?? null;
    }

    /**
     * Chaves e datas das linhas SCORM do pacote, já com os ids do destino: distinguem o que veio da origem
     * do que é trabalho novo no destino. Precisam de todo o pacote, porque uma unidade pode ocupar vários lotes.
     *
     * @param iterable $rows linhas de scorm_scoes_track do pacote
     * @param entity_map $map
     * @return int[]
     */
    public static function scorm_source_keys(iterable $rows, entity_map $map): array {
        $data = [];
        foreach ($rows as $row) {
            $data[] = [(int)$row['d']['userid'], (int)$row['d']['scormid'], (int)$row['d']['scoid'], $row['d']['attempt'], $row['d']['element'], (int)$row['d']['timemodified']];
        }
        $map->preload('user', array_column($data, 0));
        $map->preload('scorm', array_column($data, 1));
        $map->preload('scorm_scoes', array_column($data, 2));
        $keys = [];
        foreach ($data as $d) {
            $user = $map->get('user', $d[0]);
            $scorm = $map->get('scorm', $d[1]);
            $sco = $map->get('scorm_scoes', $d[2]);
            if (entity_map::usable($user) && entity_map::usable($scorm) && entity_map::usable($sco)) {
                $keys[$user->dest_id . '|' . $scorm->dest_id . '|' . $sco->dest_id . '|' . $d[3] . '|' . $d[4]] = $d[5];
            }
        }
        return $keys;
    }

    /**
     * Compara dois valores da base de dados. NULL nunca é igual a zero.
     *
     * @param mixed $a
     * @param mixed $b
     * @return bool
     */
    public static function same($a, $b): bool {
        if ($a === null || $b === null) {
            return $a === $b;
        }
        if (is_numeric($a) && is_numeric($b)) {
            return abs((float)$a - (float)$b) < 0.000001;
        }
        return (string)$a === (string)$b;
    }

    /**
     * @param string $table
     * @param array $rows linhas do pacote
     * @param array $extra dados adicionais do pacote para esta tabela
     * @return array[] itens a gravar
     */
    public function classify(string $table, array $rows, array $extra = []): array {
        if (isset(self::KEYED[$table])) {
            $items = $this->classify_keyed($table, $rows);
            if (in_array($table, self::FOLLOW_SCORM_ATTEMPT, true)) {
                if (!isset($extra['sourcekeys'])) {
                    throw new \coding_exception('sourcekeys em falta para ' . $table);
                }
                $items = $this->follow_scorm_attempt($table, array_values($rows), $items, $extra['sourcekeys']);
            }
            return $items;
        }
        $method = 'classify_' . $table;
        return $this->$method($rows, $extra);
    }

    private function classify_keyed(string $table, array $rows): array {
        global $DB;
        $rule = self::KEYED[$table];
        $this->map->preload('user', array_column(array_column($rows, 'd'), 'userid'));
        foreach ($rule['keys'] as $field => $entity) {
            $this->map->preload($entity, array_column(array_column($rows, 'd'), $field));
        }

        // Linhas do destino para os utilizadores e as primeiras chaves deste lote.
        $firstfield = array_key_first($rule['keys']);
        $users = [];
        $firsts = [];
        foreach ($rows as $row) {
            $user = $this->map->get('user', (int)$row['d']['userid']);
            if (entity_map::usable($user)) {
                $users[(int)$user->dest_id] = true;
            }
            $entry = $this->map->get($rule['keys'][$firstfield], (int)$row['d'][$firstfield]);
            if (entity_map::usable($entry)) {
                $firsts[(int)$entry->dest_id] = true;
            }
        }
        $dest = [];
        if ($users && $firsts) {
            list($inuser, $paramsuser) = $DB->get_in_or_equal(array_keys($users));
            list($infirst, $paramsfirst) = $DB->get_in_or_equal(array_keys($firsts));
            $found = $DB->get_records_select($table, "userid $inuser AND $firstfield $infirst", array_merge($paramsuser, $paramsfirst));
            foreach ($found as $record) {
                $dest[$this->dest_key($rule, (array)$record)] = (array)$record;
            }
        }

        $items = [];
        foreach ($rows as $row) {
            $items[] = $this->classify_keyed_row($table, $rule, $row, $dest);
        }
        return $items;
    }

    private function classify_keyed_row(string $table, array $rule, array $row, array $dest): array {
        $d = $row['d'];
        $user = $this->map->get('user', (int)$d['userid']);
        $courseid = null;
        $resolved = [];
        foreach ($rule['keys'] as $field => $entity) {
            $entry = $this->map->get($entity, (int)$d[$field]);
            if ($entry && $entry->courseid !== null) {
                $courseid = (int)$entry->courseid;
            }
            if (!entity_map::usable($entry)) {
                return $this->item($table, $rule['component'], $row, $user, $courseid, self::INVALID, 'dep_' . $entity);
            }
            $resolved[$field] = (int)$entry->dest_id;
        }
        if ($user !== null && $user->status === entity_map::EXCLUDED) {
            return $this->item($table, $rule['component'], $row, $user, $courseid, self::IGNORED, 'excluded_user');
        }
        // Só de inscrição: o próprio Totara cria esta linha quando o utilizador entra no grupo.
        if ($table === 'course_completions' && !$this->in_period($d['timestarted']) && !$this->in_period($d['timecompleted'])) {
            return $this->item($table, $rule['component'], $row, $user, $courseid, self::IGNORED, 'enrolment_only');
        }
        if ($user === null || in_array($user->status, [entity_map::INVALID, entity_map::MANUAL], true)) {
            return $this->item($table, $rule['component'], $row, $user, $courseid, self::INVALID, 'dep_user');
        }
        if ($user->status === entity_map::MISSING) {
            return $this->item($table, $rule['component'], $row, $user, $courseid, self::NEW, 'user_to_create');
        }

        $key = [(int)$user->dest_id];
        foreach ($resolved as $value) {
            $key[] = $value;
        }
        foreach ($rule['plainkeys'] ?? [] as $field) {
            $key[] = (string)$d[$field];
        }
        $record = $dest[implode('|', $key)] ?? null;
        if ($record === null) {
            return $this->item($table, $rule['component'], $row, $user, $courseid, self::NEW);
        }
        $destid = (int)$record['id'];
        $identical = true;
        foreach ($rule['compare'] as $field) {
            if (!self::same($d[$field] ?? null, $record[$field] ?? null)) {
                $identical = false;
                break;
            }
        }
        if ($identical) {
            return $this->item($table, $rule['component'], $row, $user, $courseid, self::IDENTICAL, null, $destid);
        }
        if ($table === 'grade_grades' && ((int)$record['overridden'] > 0 || (int)$record['locked'] > 0)) {
            return $this->item($table, $rule['component'], $row, $user, $courseid, self::DEST_NEWER, 'dest_locked', $destid);
        }
        // Concluído na origem e por concluir no destino: a conclusão é precisamente o que falta,
        // mesmo que o utilizador tenha voltado a abrir a actividade ou o curso depois da cópia.
        if (($table === 'course_modules_completion' && (int)$d['completionstate'] > 0 && (int)$record['completionstate'] === 0)
                || ($table === 'course_completions' && (int)$d['timecompleted'] > 0 && (int)$record['timecompleted'] === 0)) {
            return $this->item($table, $rule['component'], $row, $user, $courseid, self::CHANGED, 'fills_completion', $destid);
        }
        foreach ($rule['newer'] as $field) {
            if ((int)($record[$field] ?? 0) >= $this->copytime) {
                return $this->item($table, $rule['component'], $row, $user, $courseid, self::DEST_NEWER, null, $destid);
            }
        }
        // Nunca se transforma um estado concluído em incompleto.
        if ($table === 'course_modules_completion' && (int)$d['completionstate'] === 0 && (int)$record['completionstate'] > 0) {
            return $this->item($table, $rule['component'], $row, $user, $courseid, self::CONFLICT, 'would_regress', $destid);
        }
        if ($table === 'course_completions' && (int)$record['timecompleted'] > 0 && (int)$d['timecompleted'] === 0) {
            return $this->item($table, $rule['component'], $row, $user, $courseid, self::CONFLICT, 'would_regress', $destid);
        }
        return $this->item($table, $rule['component'], $row, $user, $courseid, self::CHANGED, null, $destid);
    }

    private function dest_key(array $rule, array $record): string {
        $key = [(int)$record['userid']];
        foreach ($rule['keys'] as $field => $entity) {
            $key[] = (int)$record[$field];
        }
        foreach ($rule['plainkeys'] ?? [] as $field) {
            $key[] = (string)$record[$field];
        }
        return implode('|', $key);
    }

    /**
     * SCORM: a unidade é (utilizador, scorm), com todas as tentativas. Nunca se misturam tentativas.
     *
     * @param array $rows
     * @param array $extra sourcekeys: chaves e datas de todas as linhas SCORM do pacote
     */
    /**
     * Linhas SCORM do destino, e as unidades utilizador|SCORM com trabalho novo no destino: uma linha que não veio da
     * origem, com data posterior à cópia.
     *
     * @param int[] $userids utilizadores no destino
     * @param int[] $scormids SCORM no destino
     * @param array $sourcekeys chaves e datas de todas as linhas SCORM do pacote
     * @return array [linhas por chave, unidades com trabalho novo]
     */
    private function scorm_dest_tracks(array $userids, array $scormids, array $sourcekeys): array {
        global $DB;
        $dest = [];
        $newer = [];
        if (!$userids || !$scormids) {
            return [$dest, $newer];
        }
        list($inuser, $paramsuser) = $DB->get_in_or_equal($userids);
        list($inscorm, $paramsscorm) = $DB->get_in_or_equal($scormids);
        $found = $DB->get_records_select('scorm_scoes_track', "userid $inuser AND scormid $inscorm", array_merge($paramsuser, $paramsscorm),
            '', 'id, userid, scormid, scoid, attempt, element, value, timemodified');
        foreach ($found as $record) {
            $key = $record->userid . '|' . $record->scormid . '|' . $record->scoid . '|' . $record->attempt . '|' . $record->element;
            $dest[$key] = $record;
            if ((int)$record->timemodified >= $this->copytime && ($sourcekeys[$key] ?? null) !== (int)$record->timemodified) {
                $newer[$record->userid . '|' . $record->scormid] = true;
            }
        }
        return [$dest, $newer];
    }

    /**
     * Uma conclusão ou nota de SCORM que seria escrita passa a Destino mais recente quando o utilizador tem trabalho novo
     * nesse SCORM no destino. Recuperá-la não serve: o Totara recalcula-a a partir da tentativa do destino.
     *
     * @param string $table course_modules_completion ou grade_grades
     * @param array $rows linhas do pacote, pela mesma ordem dos itens
     * @param array $items itens já classificados
     * @param array $sourcekeys chaves e datas de todas as linhas SCORM do pacote
     * @return array itens
     */
    private function follow_scorm_attempt(string $table, array $rows, array $items, array $sourcekeys): array {
        global $DB;
        $field = $table === 'grade_grades' ? 'itemid' : 'coursemoduleid';
        $entity = $table === 'grade_grades' ? 'grade_items' : 'course_modules';
        $targets = [];
        foreach ($items as $index => $item) {
            if (in_array($item['classification'], [self::NEW, self::CHANGED], true) && $item['dest_userid'] !== null) {
                $entry = $this->map->get($entity, (int)$rows[$index]['d'][$field]);
                if (entity_map::usable($entry)) {
                    $targets[$index] = (int)$entry->dest_id;
                }
            }
        }
        if (!$targets) {
            return $items;
        }
        list($in, $params) = $DB->get_in_or_equal(array_values(array_unique($targets)));
        if ($table === 'grade_grades') {
            $found = $DB->get_records_sql("SELECT id, iteminstance AS scormid FROM {grade_items} WHERE id $in AND itemtype = ? AND itemmodule = ?",
                array_merge($params, ['mod', 'scorm']));
        } else {
            $found = $DB->get_records_sql("SELECT cm.id, cm.instance AS scormid FROM {course_modules} cm JOIN {modules} m ON m.id = cm.module WHERE cm.id $in AND m.name = ?",
                array_merge($params, ['scorm']));
        }
        $users = [];
        $scorms = [];
        foreach ($targets as $index => $destid) {
            if (!isset($found[$destid])) {
                unset($targets[$index]);
                continue;
            }
            $users[$items[$index]['dest_userid']] = true;
            $scorms[(int)$found[$destid]->scormid] = true;
        }
        list(, $newer) = $this->scorm_dest_tracks(array_keys($users), array_keys($scorms), $sourcekeys);
        foreach ($targets as $index => $destid) {
            if (isset($newer[$items[$index]['dest_userid'] . '|' . (int)$found[$destid]->scormid])) {
                $items[$index]['classification'] = self::DEST_NEWER;
                $items[$index]['reason'] = 'scorm_dest_newer';
            }
        }
        return $items;
    }

    private function classify_scorm_scoes_track(array $rows, array $extra): array {
        global $DB;
        $data = array_column($rows, 'd');
        $this->map->preload('user', array_column($data, 'userid'));
        $this->map->preload('scorm', array_column($data, 'scormid'));
        $this->map->preload('scorm_scoes', array_column($data, 'scoid'));

        $users = [];
        $scorms = [];
        foreach ($data as $d) {
            $user = $this->map->get('user', (int)$d['userid']);
            if (entity_map::usable($user) && entity_map::usable($this->map->get('scorm', (int)$d['scormid']))) {
                $users[(int)$user->dest_id] = true;
                $scorms[(int)$this->map->get('scorm', (int)$d['scormid'])->dest_id] = true;
            }
        }
        list($dest, $newer) = $this->scorm_dest_tracks(array_keys($users), array_keys($scorms), $extra['sourcekeys']);

        $items = [];
        foreach ($rows as $row) {
            $d = $row['d'];
            $user = $this->map->get('user', (int)$d['userid']);
            $scorm = $this->map->get('scorm', (int)$d['scormid']);
            $sco = $this->map->get('scorm_scoes', (int)$d['scoid']);
            $courseid = $scorm && $scorm->courseid !== null ? (int)$scorm->courseid : null;
            if ($user !== null && $user->status === entity_map::EXCLUDED) {
                $items[] = $this->item('scorm_scoes_track', 'scorm', $row, $user, $courseid, self::IGNORED, 'excluded_user');
            } else if (!entity_map::usable($scorm)) {
                $items[] = $this->item('scorm_scoes_track', 'scorm', $row, $user, $courseid, self::INVALID, 'dep_scorm');
            } else if (!entity_map::usable($sco)) {
                $items[] = $this->item('scorm_scoes_track', 'scorm', $row, $user, $courseid, self::INVALID, 'dep_sco');
            } else if ($user === null || in_array($user->status, [entity_map::INVALID, entity_map::MANUAL], true)) {
                $items[] = $this->item('scorm_scoes_track', 'scorm', $row, $user, $courseid, self::INVALID, 'dep_user');
            } else if ($user->status === entity_map::MISSING) {
                $items[] = $this->item('scorm_scoes_track', 'scorm', $row, $user, $courseid, self::NEW, 'user_to_create');
            } else {
                $unit = $user->dest_id . '|' . $scorm->dest_id;
                $record = $dest[$unit . '|' . $sco->dest_id . '|' . $d['attempt'] . '|' . $d['element']] ?? null;
                if ($record !== null && (string)$record->value === (string)$d['value']) {
                    $items[] = $this->item('scorm_scoes_track', 'scorm', $row, $user, $courseid, self::IDENTICAL, null, (int)$record->id);
                } else if (isset($newer[$unit])) {
                    $items[] = $this->item('scorm_scoes_track', 'scorm', $row, $user, $courseid, self::DEST_NEWER, null, $record ? (int)$record->id : null);
                } else if ($record !== null) {
                    $items[] = $this->item('scorm_scoes_track', 'scorm', $row, $user, $courseid, self::CHANGED, null, (int)$record->id);
                } else {
                    $items[] = $this->item('scorm_scoes_track', 'scorm', $row, $user, $courseid, self::NEW);
                }
            }
        }
        return $items;
    }

    /**
     * Feedback: os valores das respostas nunca são gravados nos itens nem nos relatórios.
     *
     * @param array $rows
     * @param array $extra values: linhas de feedback_value do pacote, por resposta
     */
    private function classify_feedback_completed(array $rows, array $extra): array {
        global $DB;
        $data = array_column($rows, 'd');
        $this->map->preload('user', array_column($data, 'userid'));
        $this->map->preload('feedback', array_column($data, 'feedback'));
        $itemids = [];
        foreach ($data as $d) {
            foreach ($extra['values'][(int)$d['id']] ?? [] as $value) {
                $itemids[] = (int)$value['item'];
            }
        }
        $this->map->preload('feedback_item', $itemids);

        $users = [];
        foreach ($data as $d) {
            $user = $this->map->get('user', (int)$d['userid']);
            if (entity_map::usable($user)) {
                $users[(int)$user->dest_id] = true;
            }
        }
        $dest = [];
        $destvalues = [];
        if ($users) {
            list($inuser, $paramsuser) = $DB->get_in_or_equal(array_keys($users));
            list($infb, $paramsfb) = $DB->get_in_or_equal(array_values(array_unique(array_map('intval', array_column($data, 'feedback')))));
            $found = $DB->get_records_select('feedback_completed', "userid $inuser AND feedback $infb", array_merge($paramsuser, $paramsfb));
            foreach ($found as $record) {
                $dest[$record->feedback . '|' . $record->userid . '|' . $record->courseid][] = $record;
            }
            if ($found) {
                list($incompleted, $paramscompleted) = $DB->get_in_or_equal(array_keys($found));
                foreach ($DB->get_records_select('feedback_value', "completed $incompleted", $paramscompleted, '', 'id, completed, item, value') as $value) {
                    $destvalues[(int)$value->completed][(int)$value->item] = (string)$value->value;
                }
            }
        }

        $items = [];
        foreach ($rows as $row) {
            $items[] = $this->classify_feedback_row($row, $extra['values'][(int)$row['d']['id']] ?? [], $dest, $destvalues);
        }
        return $items;
    }

    private function classify_feedback_row(array $row, array $values, array $dest, array $destvalues): array {
        $d = $row['d'];
        $table = 'feedback_completed';
        $user = $this->map->get('user', (int)$d['userid']);
        $feedback = $this->map->get('feedback', (int)$d['feedback']);
        $courseid = $feedback && $feedback->courseid !== null ? (int)$feedback->courseid : ((int)$d['courseid'] ?: null);
        if ($user !== null && $user->status === entity_map::EXCLUDED) {
            return $this->item($table, 'feedback', $row, $user, $courseid, self::IGNORED, 'excluded_user');
        }
        if (!entity_map::usable($feedback)) {
            return $this->item($table, 'feedback', $row, $user, $courseid, self::INVALID, 'dep_feedback');
        }
        $source = [];
        foreach ($values as $value) {
            if (!entity_map::usable($this->map->get('feedback_item', (int)$value['item']))) {
                return $this->item($table, 'feedback', $row, $user, $courseid, self::INVALID, 'dep_feedback_item');
            }
            $source[(int)$value['item']] = (string)$value['value'];
        }
        if ($user === null || in_array($user->status, [entity_map::INVALID, entity_map::MANUAL], true)) {
            return $this->item($table, 'feedback', $row, $user, $courseid, self::INVALID, 'dep_user');
        }
        if ($user->status === entity_map::MISSING) {
            return $this->item($table, 'feedback', $row, $user, $courseid, self::NEW, 'user_to_create');
        }
        $records = $dest[$feedback->dest_id . '|' . $user->dest_id . '|' . (int)$d['courseid']] ?? [];
        $newest = null;
        foreach ($records as $record) {
            if ((int)$record->timemodified === (int)$d['timemodified']) {
                $same = ($destvalues[(int)$record->id] ?? []) == $source;
                return $this->item($table, 'feedback', $row, $user, $courseid, $same ? self::IDENTICAL : self::CONFLICT, $same ? null : 'values_differ', (int)$record->id);
            }
            if ($newest === null || (int)$record->timemodified > (int)$newest->timemodified) {
                $newest = $record;
            }
        }
        if ($newest === null) {
            return $this->item($table, 'feedback', $row, $user, $courseid, self::NEW);
        }
        if ((int)$newest->timemodified >= $this->copytime) {
            return $this->item($table, 'feedback', $row, $user, $courseid, self::DEST_NEWER, null, (int)$newest->id);
        }
        // Resposta identificada mais antiga: o Totara substitui-a. Numa anónima não há como relacionar as duas.
        if ((int)$d['anonymous_response'] === 2) {
            return $this->item($table, 'feedback', $row, $user, $courseid, self::CHANGED, null, (int)$newest->id);
        }
        return $this->item($table, 'feedback', $row, $user, $courseid, self::CONFLICT, 'anonymous_other_response', (int)$newest->id);
    }

    /**
     * Certificados: nunca há duas emissões para (utilizador, certificado), e o código nunca é alterado.
     */
    private function classify_customcert_issues(array $rows, array $extra): array {
        global $DB;
        $data = array_column($rows, 'd');
        $this->map->preload('user', array_column($data, 'userid'));
        $this->map->preload('customcert', array_column($data, 'customcertid'));

        $users = [];
        foreach ($data as $d) {
            $user = $this->map->get('user', (int)$d['userid']);
            if (entity_map::usable($user)) {
                $users[(int)$user->dest_id] = true;
            }
        }
        $dest = [];
        if ($users) {
            list($inuser, $params) = $DB->get_in_or_equal(array_keys($users));
            foreach ($DB->get_records_select('customcert_issues', "userid $inuser", $params) as $record) {
                $dest[$record->customcertid . '|' . $record->userid] = $record;
            }
        }
        $codes = [];
        $sourcecodes = array_values(array_filter(array_column($data, 'code'), 'strlen'));
        if ($sourcecodes) {
            list($incode, $params) = $DB->get_in_or_equal($sourcecodes);
            foreach ($DB->get_records_select('customcert_issues', "code $incode", $params, '', 'id, code, userid, customcertid') as $record) {
                $codes[$record->code] = $record;
            }
        }

        $items = [];
        foreach ($rows as $row) {
            $d = $row['d'];
            $table = 'customcert_issues';
            $user = $this->map->get('user', (int)$d['userid']);
            $cert = $this->map->get('customcert', (int)$d['customcertid']);
            $courseid = $cert && $cert->courseid !== null ? (int)$cert->courseid : null;
            if ($user !== null && $user->status === entity_map::EXCLUDED) {
                $items[] = $this->item($table, 'customcert', $row, $user, $courseid, self::IGNORED, 'excluded_user');
                continue;
            }
            if (!entity_map::usable($cert)) {
                $items[] = $this->item($table, 'customcert', $row, $user, $courseid, self::INVALID, 'dep_customcert');
                continue;
            }
            if ($user === null || in_array($user->status, [entity_map::INVALID, entity_map::MANUAL], true)) {
                $items[] = $this->item($table, 'customcert', $row, $user, $courseid, self::INVALID, 'dep_user');
                continue;
            }
            $template = isset($extra['templates_differ'][(int)$cert->source_id]);
            $record = entity_map::usable($user) ? ($dest[$cert->dest_id . '|' . $user->dest_id] ?? null) : null;
            if ($record !== null) {
                if ((string)$record->code === (string)$d['code']) {
                    $items[] = $this->item($table, 'customcert', $row, $user, $courseid, self::IDENTICAL, null, (int)$record->id);
                } else {
                    // Decisão do cliente: fica a emissão do destino; o código da origem segue para o relatório.
                    $items[] = $this->item($table, 'customcert', $row, $user, $courseid, self::DEST_NEWER, 'code_differs', (int)$record->id, $d['code']);
                }
                continue;
            }
            if (isset($codes[$d['code']])) {
                $items[] = $this->item($table, 'customcert', $row, $user, $courseid, self::CONFLICT, 'code_in_use', (int)$codes[$d['code']]->id);
            } else if ($template) {
                $items[] = $this->item($table, 'customcert', $row, $user, $courseid, self::CONFLICT, 'template_differs');
            } else {
                $items[] = $this->item($table, 'customcert', $row, $user, $courseid, self::NEW, $user->status === entity_map::MISSING ? 'user_to_create' : null);
            }
        }
        return $items;
    }

    /**
     * Logs: acrescentados, nunca actualizados. Corre depois de se saber quem terá conta.
     */
    private function classify_logstore_standard_log(array $rows, array $extra): array {
        global $DB;
        $data = array_column($rows, 'd');
        $this->map->preload('user', array_column($data, 'userid'));
        $this->map->preload('context', array_column($data, 'contextid'));

        $times = array_map('intval', array_column($data, 'timecreated'));
        $existing = [];
        // Na escrita não se procura: o destino só cria eventos com a data de hoje, por isso nada novo surge no período,
        // e os eventos iguais no mesmo segundo gravados num lote anterior pareceriam já existir.
        if ($times && empty($extra['skip_existing'])) {
            $found = $DB->get_records_select('logstore_standard_log', 'timecreated >= ? AND timecreated <= ?', [min($times), max($times)],
                '', 'id, eventname, userid, contextid, timecreated');
            foreach ($found as $record) {
                $existing[$record->eventname . '|' . $record->userid . '|' . $record->contextid . '|' . $record->timecreated] = (int)$record->id;
            }
        }

        $items = [];
        foreach ($rows as $row) {
            $d = $row['d'];
            $table = 'logstore_standard_log';
            $sourceuser = (int)$d['userid'];
            $user = $sourceuser > 0 ? $this->map->get('user', $sourceuser) : null;
            $courseid = (int)($d['courseid'] ?? 0) ?: null;
            // Sem utilizador real (visitante, sistema, envio de emails): não conta para o tempo no curso.
            if ($sourceuser <= 0) {
                $items[] = $this->item($table, 'logs', $row, null, $courseid, self::IGNORED, 'system_event');
                continue;
            }
            if ($user !== null && $user->status === entity_map::EXCLUDED) {
                $items[] = $this->item($table, 'logs', $row, $user, $courseid, self::IGNORED, 'excluded_user');
                continue;
            }
            if (($user === null || !entity_map::usable($user)) && !($user && $user->status === entity_map::MISSING && $user->detail === 'create')) {
                $items[] = $this->item($table, 'logs', $row, $user, $courseid, self::IGNORED, 'no_account');
                continue;
            }
            $context = $this->map->get('context', (int)$d['contextid']);
            if ($context === null || $context->status === entity_map::INVALID) {
                $items[] = $this->item($table, 'logs', $row, $user, $courseid, self::INVALID, 'dep_context');
                continue;
            }
            if (entity_map::usable($context) && entity_map::usable($user)) {
                $key = $d['eventname'] . '|' . (int)$user->dest_id . '|' . (int)$context->dest_id . '|' . (int)$d['timecreated'];
                if (isset($existing[$key])) {
                    $items[] = $this->item($table, 'logs', $row, $user, $courseid, self::IDENTICAL, null, $existing[$key]);
                    continue;
                }
            }
            $items[] = $this->item($table, 'logs', $row, $user, $courseid, self::NEW, $user && $user->status === entity_map::MISSING ? 'user_to_create' : null);
        }
        return $items;
    }

    private function in_period($time): bool {
        return (int)$time >= $this->period['start'] && (int)$time < $this->period['end'];
    }

    private function item(string $table, string $component, array $row, ?\stdClass $user, ?int $courseid, string $classification,
            ?string $reason = null, ?int $destid = null, ?string $detail = null): array {
        return [
            'component' => $component,
            'source_table' => $table,
            'source_recordid' => (int)$row['sid'],
            'destination_recordid' => $destid,
            'source_userid' => isset($row['d']['userid']) ? (int)$row['d']['userid'] : null,
            'dest_userid' => $user !== null && $user->dest_id !== null ? (int)$user->dest_id : null,
            'courseid' => $courseid,
            'content_hash' => (string)$row['h'],
            'role' => (string)$row['role'],
            'classification' => $classification,
            'reason' => $reason,
            'detail' => $detail,
        ];
    }
}
