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

defined('MOODLE_INTERNAL') || die();

/**
 * Cria as contas em falta de quem tem progresso a recuperar (decisão P16).
 *
 * A autenticação é externa: no primeiro acesso, o auth_saml2 procura a conta pelo username.
 *
 * @package    local_progressrecovery
 * @copyright  2026 IT EDUdigital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class account_creator {
    public const AUTH = 'saml2';

    /**
     * Confirma, no momento da escrita, que a conta continua em falta: o destino pode ter mudado desde a comparação.
     *
     * @param array $source linha da origem
     * @return array reason (null se a conta deve ser criada), dest_id (conta existente)
     */
    public static function check(array $source): array {
        global $DB;
        $found = $DB->get_records_select('user', 'username = ?', [$source['username']], '', 'id, deleted', 0, 1);
        if ($found) {
            $user = reset($found);
            return (int)$user->deleted === 0 ? ['reason' => 'account_exists', 'dest_id' => (int)$user->id] : ['reason' => 'deleted_destination', 'dest_id' => null];
        }
        $email = strtolower((string)$source['email']);
        if ($email !== '' && $email !== 'na@na.na' && $DB->get_records_select('user', 'deleted = 0 AND LOWER(email) = ?', [$email], '', 'id', 0, 1)) {
            return ['reason' => 'email_exists', 'dest_id' => null];
        }
        return ['reason' => null, 'dest_id' => null];
    }

    /**
     * @param array $source linha da origem
     * @return int id da conta criada
     */
    public static function create(array $source): int {
        global $CFG;
        return (int)user_create_user((object)[
            'username' => $source['username'],
            'idnumber' => (string)$source['idnumber'],
            'email' => (string)$source['email'],
            'firstname' => (string)$source['firstname'],
            'lastname' => (string)$source['lastname'],
            'lang' => (string)$source['lang'],
            'timezone' => (string)$source['timezone'],
            'auth' => self::AUTH,
            'password' => AUTH_PASSWORD_NOT_CACHED,
            'confirmed' => 1,
            'mnethostid' => (int)$CFG->mnet_localhost_id,
        ], false);
    }
}
