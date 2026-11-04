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

namespace local_progressrecovery\audit;

defined('MOODLE_INTERNAL') || die();

/**
 * Registo de auditoria. Nunca recebe segredos nem respostas de inquéritos.
 *
 * @package    local_progressrecovery
 * @copyright  2026 IT EDUdigital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class audit_manager {
    public const RESULT_OK = 'ok';
    public const RESULT_REJECTED = 'rejected';

    /**
     * @param string $action
     * @param string $result
     * @param array $details
     * @param int|null $packageid
     */
    public static function log(string $action, string $result, array $details = [], ?int $packageid = null): void {
        global $DB, $USER;
        $DB->insert_record('local_progressrecovery_audit', (object)[
            'packageid' => $packageid,
            'userid' => (int)$USER->id,
            'action' => $action,
            'details' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'result' => $result,
            'timecreated' => time(),
        ]);
    }

    /**
     * @param int $limit
     * @param int|null $packageid
     * @param string|null $result
     * @return \stdClass[] do mais recente para o mais antigo
     */
    public static function recent(int $limit, ?int $packageid = null, ?string $result = null): array {
        global $DB;
        $conditions = [];
        if ($packageid !== null) {
            $conditions['packageid'] = $packageid;
        }
        if ($result !== null) {
            $conditions['result'] = $result;
        }
        return array_values($DB->get_records('local_progressrecovery_audit', $conditions, 'id DESC', '*', 0, $limit));
    }
}
