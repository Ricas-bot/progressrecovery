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
 * Correspondência das estruturas: IDs iguais não chegam, o identificador natural tem de coincidir.
 *
 * Só lê as tabelas do Totara. Devolve as linhas a gravar no mapa de entidades.
 *
 * @package    local_progressrecovery
 * @copyright  2026 IT EDUdigital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class entity_matcher {
    /** @var entity_map */
    private $map;
    /** @var array nomes de utilizador excluídos, em minúsculas */
    private $excluded;

    /**
     * @param entity_map $map
     * @param string[] $excluded
     */
    public function __construct(entity_map $map, array $excluded) {
        $this->map = $map;
        $this->excluded = array_flip(array_map('strtolower', $excluded));
    }

    /**
     * @param string $entity tabela da estrutura
     * @param array $rows linhas do pacote
     * @return array[] source_id, dest_id, natural_key, status, detail, courseid, parent_id
     */
    public function match(string $entity, array $rows): array {
        $data = array_map(function ($row) {
            return $row['d'];
        }, $rows);
        $method = 'match_' . $entity;
        return $this->$method($data);
    }

    private function match_user(array $rows): array {
        global $DB;
        list($in, $params) = $DB->get_in_or_equal(array_column($rows, 'username'));
        $dest = [];
        foreach ($DB->get_records_select('user', "username $in", $params, '', 'id, username, deleted') as $user) {
            $dest[strtolower($user->username)] = $user;
        }
        // Só os emails de quem não tem conta, para detectar a mesma pessoa com outro username.
        $emails = [];
        foreach ($rows as $row) {
            $email = strtolower((string)$row['email']);
            if (!isset($dest[strtolower($row['username'])]) && $email !== '' && $email !== 'na@na.na') {
                $emails[$email] = true;
            }
        }
        $taken = [];
        if ($emails) {
            list($in, $params) = $DB->get_in_or_equal(array_keys($emails));
            foreach ($DB->get_records_select('user', "deleted = 0 AND LOWER(email) $in", $params, '', 'id, email') as $user) {
                $taken[strtolower($user->email)] = true;
            }
        }
        $out = [];
        foreach ($rows as $row) {
            $key = strtolower($row['username']);
            $user = $dest[$key] ?? null;
            $destid = null;
            $detail = null;
            if (isset($this->excluded[$key])) {
                $status = entity_map::EXCLUDED;
            } else if ($user && (int)$user->deleted === 0) {
                $destid = (int)$user->id;
                $status = $destid === (int)$row['id'] ? entity_map::OK : entity_map::REMAPPED;
            } else if ($user) {
                $status = entity_map::INVALID;
                $detail = 'deleted_destination';
            } else if ((int)$row['deleted'] === 1) {
                $status = entity_map::INVALID;
                $detail = 'deleted_source';
            } else if (isset($taken[strtolower((string)$row['email'])])) {
                $status = entity_map::MANUAL;
                $detail = 'email_exists';
            } else {
                $status = entity_map::MISSING;
            }
            $out[] = self::entry($row['id'], $destid, $row['username'], $status, $detail);
        }
        return $out;
    }

    private function match_course(array $rows): array {
        global $DB;
        $dest = $this->by_id('course', array_column($rows, 'id'), 'id, shortname');
        list($in, $params) = $DB->get_in_or_equal(array_column($rows, 'shortname'));
        $byname = [];
        foreach ($DB->get_records_select('course', "shortname $in", $params, '', 'id, shortname') as $course) {
            $byname[$course->shortname] = (int)$course->id;
        }
        $out = [];
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            if (isset($dest[$id]) && $dest[$id]->shortname === $row['shortname']) {
                $out[] = self::entry($id, $id, $row['shortname'], entity_map::OK, null, $id);
            } else if (isset($byname[$row['shortname']])) {
                $out[] = self::entry($id, $byname[$row['shortname']], $row['shortname'], entity_map::MANUAL, 'other_id');
            } else {
                $out[] = self::entry($id, null, $row['shortname'], entity_map::INVALID, isset($dest[$id]) ? 'natural_differs' : 'not_found');
            }
        }
        return $out;
    }

    private function match_course_modules(array $rows): array {
        global $DB;
        $this->map->preload('course', array_column($rows, 'course'));
        list($in, $params) = $DB->get_in_or_equal(array_column($rows, 'id'));
        $dest = $DB->get_records_sql("SELECT cm.id, cm.course, cm.instance, cm.deletioninprogress, m.name AS module_name
                                        FROM {course_modules} cm
                                        JOIN {modules} m ON m.id = cm.module
                                       WHERE cm.id $in", $params);
        $out = [];
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            $natural = $row['course'] . '/' . $row['module_name'] . '/' . $row['instance'];
            $found = $dest[$id] ?? null;
            if (!$found) {
                $out[] = self::entry($id, null, $natural, entity_map::INVALID, 'not_found');
            } else if ((int)$found->course !== (int)$row['course'] || $found->module_name !== $row['module_name']
                    || (int)$found->instance !== (int)$row['instance']) {
                $out[] = self::entry($id, null, $natural, entity_map::INVALID, 'natural_differs');
            } else if ((int)$found->deletioninprogress === 1) {
                $out[] = self::entry($id, null, $natural, entity_map::INVALID, 'being_deleted');
            } else if (!entity_map::usable($this->map->get('course', (int)$row['course']))) {
                $out[] = self::entry($id, null, $natural, entity_map::INVALID, 'course');
            } else {
                $out[] = self::entry($id, $id, $natural, entity_map::OK, null, (int)$row['course']);
            }
        }
        return $out;
    }

    private function match_context(array $rows): array {
        global $DB;
        $dest = $this->by_id('context', array_column($rows, 'id'), 'id, contextlevel, instanceid');
        $users = [];
        foreach ($rows as $row) {
            if ((int)$row['contextlevel'] === 30) {
                $users[] = (int)$row['instanceid'];
            }
        }
        $this->map->preload('user', $users);
        // O contexto de um utilizador segue a tradução do utilizador, porque os ids podem colidir.
        $destusers = [];
        foreach ($users as $user) {
            $entry = $this->map->get('user', $user);
            if (entity_map::usable($entry)) {
                $destusers[] = (int)$entry->dest_id;
            }
        }
        $usercontexts = [];
        if ($destusers) {
            list($in, $params) = $DB->get_in_or_equal($destusers);
            foreach ($DB->get_records_select('context', "contextlevel = 30 AND instanceid $in", $params, '', 'id, instanceid') as $context) {
                $usercontexts[(int)$context->instanceid] = (int)$context->id;
            }
        }
        $out = [];
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            $level = (int)$row['contextlevel'];
            $instance = (int)$row['instanceid'];
            $natural = $level . '/' . $instance;
            if ($level === 30) {
                $entry = $this->map->get('user', $instance);
                if (!entity_map::usable($entry)) {
                    $out[] = self::entry($id, null, $natural, entity_map::MISSING, 'user');
                } else if (!isset($usercontexts[(int)$entry->dest_id])) {
                    $out[] = self::entry($id, null, $natural, entity_map::INVALID, 'not_found');
                } else {
                    $destid = $usercontexts[(int)$entry->dest_id];
                    $out[] = self::entry($id, $destid, $natural, $destid === $id ? entity_map::OK : entity_map::REMAPPED);
                }
                continue;
            }
            $found = $dest[$id] ?? null;
            if ($found && (int)$found->contextlevel === $level && (int)$found->instanceid === $instance) {
                $out[] = self::entry($id, $id, $natural, entity_map::OK);
            } else {
                $out[] = self::entry($id, null, $natural, entity_map::INVALID, $found ? 'natural_differs' : 'not_found');
            }
        }
        return $out;
    }

    private function match_grade_items(array $rows): array {
        return $this->compare_same_id('grade_items', $rows, ['courseid', 'itemtype', 'itemmodule', 'iteminstance', 'itemnumber'], 'courseid');
    }

    private function match_course_completion_criteria(array $rows): array {
        return $this->compare_same_id('course_completion_criteria', $rows,
            ['course', 'criteriatype', 'module', 'moduleinstance', 'courseinstance', 'role'], 'course');
    }

    private function match_scorm(array $rows): array {
        return $this->compare_same_id('scorm', $rows, ['course', 'sha1hash'], 'course');
    }

    private function match_scorm_scoes(array $rows): array {
        global $DB;
        $this->map->preload('scorm', array_column($rows, 'scorm'));
        list($in, $params) = $DB->get_in_or_equal(array_column($rows, 'scorm'));
        $dest = [];
        foreach ($DB->get_records_select('scorm_scoes', "scorm $in", $params, '', 'id, scorm, identifier') as $sco) {
            $dest[$sco->scorm . '/' . $sco->identifier] = (int)$sco->id;
        }
        $out = [];
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            $natural = $row['scorm'] . '/' . $row['identifier'];
            $scorm = $this->map->get('scorm', (int)$row['scorm']);
            if (!entity_map::usable($scorm)) {
                $out[] = self::entry($id, null, $natural, entity_map::INVALID, 'scorm');
            } else if (!isset($dest[$natural])) {
                $out[] = self::entry($id, null, $natural, entity_map::INVALID, 'not_found');
            } else {
                $out[] = self::entry($id, $dest[$natural], $natural, $dest[$natural] === $id ? entity_map::OK : entity_map::REMAPPED,
                    null, $scorm->courseid === null ? null : (int)$scorm->courseid, (int)$row['scorm']);
            }
        }
        return $out;
    }

    private function match_feedback(array $rows): array {
        return $this->compare_same_id('feedback', $rows, ['course'], 'course');
    }

    private function match_feedback_item(array $rows): array {
        return $this->compare_same_id('feedback_item', $rows, ['feedback', 'typ', 'hasvalue', 'presentation'], null, entity_map::MANUAL, 'feedback');
    }

    private function match_customcert(array $rows): array {
        return $this->compare_same_id('customcert', $rows, ['course', 'templateid'], 'course');
    }

    private function match_customcert_templates(array $rows): array {
        return $this->compare_same_id('customcert_templates', $rows, ['name'], null, entity_map::MANUAL);
    }

    private function match_customcert_pages(array $rows): array {
        return $this->compare_same_id('customcert_pages', $rows, ['templateid', 'width', 'height'], null, entity_map::MANUAL, 'templateid');
    }

    private function match_customcert_elements(array $rows): array {
        return $this->compare_same_id('customcert_elements', $rows, ['pageid', 'element', 'data'], null, entity_map::MANUAL, 'pageid');
    }

    private function match_files(array $rows): array {
        global $DB;
        list($in, $params) = $DB->get_in_or_equal(array_column($rows, 'contenthash'));
        $dest = [];
        foreach ($DB->get_records_select('files', "contenthash $in", $params, '', 'id, contenthash, contextid, component, filearea, itemid, filepath, filename') as $file) {
            $dest[self::file_key((array)$file)] = (int)$file->id;
        }
        $out = [];
        foreach ($rows as $row) {
            $key = self::file_key($row);
            $id = (int)$row['id'];
            $natural = $row['filearea'] . $row['filepath'] . $row['filename'];
            if (isset($dest[$key])) {
                $out[] = self::entry($id, $dest[$key], $natural, $dest[$key] === $id ? entity_map::OK : entity_map::REMAPPED);
            } else {
                $out[] = self::entry($id, null, $natural, entity_map::MANUAL, 'file_missing');
            }
        }
        return $out;
    }

    private static function file_key(array $file): string {
        return implode('|', [$file['contenthash'], $file['contextid'], $file['component'], $file['filearea'], $file['itemid'], $file['filepath'], $file['filename']]);
    }

    /**
     * Estruturas que têm de existir com o mesmo id e os mesmos campos naturais.
     */
    private function compare_same_id(string $table, array $rows, array $fields, ?string $coursefield, string $mismatch = entity_map::INVALID, ?string $parentfield = null): array {
        $select = 'id, ' . implode(', ', array_filter($fields, function ($f) {
            return !in_array($f, ['presentation', 'data'], true);
        }));
        if (in_array('presentation', $fields, true)) {
            $select .= ', presentation';
        }
        if (in_array('data', $fields, true)) {
            $select .= ', data';
        }
        $dest = $this->by_id($table, array_column($rows, 'id'), $select);
        $out = [];
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            $natural = implode('/', array_map(function ($f) use ($row) {
                return in_array($f, ['presentation', 'data'], true) ? '' : (string)$row[$f];
            }, $fields));
            $courseid = $coursefield !== null ? (int)$row[$coursefield] : null;
            $parent = $parentfield !== null ? (int)$row[$parentfield] : null;
            if (!isset($dest[$id])) {
                $out[] = self::entry($id, null, $natural, $mismatch === entity_map::MANUAL ? entity_map::MANUAL : entity_map::INVALID, 'not_found', $courseid, $parent);
                continue;
            }
            $differs = null;
            foreach ($fields as $field) {
                if ((string)$dest[$id]->$field !== (string)$row[$field]) {
                    $differs = $field;
                    break;
                }
            }
            if ($differs !== null) {
                $out[] = self::entry($id, null, $natural, $mismatch, 'differs_' . $differs, $courseid, $parent);
            } else {
                $out[] = self::entry($id, $id, $natural, entity_map::OK, null, $courseid, $parent);
            }
        }
        return $out;
    }

    private function by_id(string $table, array $ids, string $fields): array {
        global $DB;
        list($in, $params) = $DB->get_in_or_equal(array_map('intval', $ids));
        $out = [];
        foreach ($DB->get_records_select($table, "id $in", $params, '', $fields) as $row) {
            $out[(int)$row->id] = $row;
        }
        return $out;
    }

    private static function entry($sourceid, ?int $destid, string $natural, string $status, ?string $detail = null, ?int $courseid = null, ?int $parent = null): array {
        return [
            'source_id' => (int)$sourceid,
            'dest_id' => $destid,
            'natural_key' => mb_substr($natural, 0, 255),
            'status' => $status,
            'detail' => $detail,
            'courseid' => $courseid,
            'parent_id' => $parent,
        ];
    }
}
