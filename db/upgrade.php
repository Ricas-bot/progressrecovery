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

/**
 * Actualização do plugin.
 *
 * @package    local_progressrecovery
 * @copyright  2026 IT EDUdigital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_progressrecovery_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026110201) {
        // Fase 3: execuções, correspondência de estruturas e classificação dos registos.
        foreach (['local_progressrecovery_jobs', 'local_progressrecovery_entities', 'local_progressrecovery_items'] as $table) {
            if (!$dbman->table_exists($table)) {
                $dbman->install_one_table_from_xmldb_file(__DIR__ . '/install.xml', $table);
            }
        }
        upgrade_plugin_savepoint(true, 2026110201, 'local', 'progressrecovery');
    }

    if ($oldversion < 2026110202) {
        // Fase 4: operações de recuperação e procura dos resultados da comparação por registo.
        if (!$dbman->table_exists('local_progressrecovery_ops')) {
            $dbman->install_one_table_from_xmldb_file(__DIR__ . '/install.xml', 'local_progressrecovery_ops');
        }
        $table = new xmldb_table('local_progressrecovery_items');
        $index = new xmldb_index('job_record', XMLDB_INDEX_NOTUNIQUE, ['jobid', 'source_table', 'source_recordid']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }
        upgrade_plugin_savepoint(true, 2026110202, 'local', 'progressrecovery');
    }

    return true;
}
