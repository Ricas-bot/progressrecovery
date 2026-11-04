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

use local_progressrecovery\import\compatibility_checker;
use local_progressrecovery\import\import_manager;

defined('MOODLE_INTERNAL') || die();

/**
 * Dados dos pacotes para os templates.
 *
 * @package    local_progressrecovery
 * @copyright  2026 IT EDUdigital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class package_view {
    private const BADGES = [
        compatibility_checker::COMPATIBLE => 'label-success',
        compatibility_checker::WARNINGS => 'label-warning',
        compatibility_checker::MANUAL => 'label-info',
        compatibility_checker::INCOMPATIBLE => 'label-danger',
    ];

    /**
     * Resumo de um pacote, para o painel e o histórico.
     *
     * @param \stdClass $package
     * @return array
     */
    public static function summary(\stdClass $package): array {
        $manifest = json_decode($package->manifest, true);
        $validation = json_decode($package->validation, true);
        $pending = count($validation['compatibility']['global']);
        foreach ($validation['compatibility']['components'] as $info) {
            foreach ($info['reasons'] as $reason) {
                if ($reason['status'] !== compatibility_checker::COMPATIBLE) {
                    $pending++;
                }
            }
        }
        return [
            'id' => (int)$package->id,
            'url' => (new \moodle_url('/local/progressrecovery/package.php', ['id' => $package->id]))->out(false),
            'filename' => $package->filename,
            'uuid' => $package->package_uuid,
            'sha256' => $package->package_hash,
            'size' => display_size((int)$package->filesize),
            'version' => $package->source_version,
            'created' => self::date((int)$package->timecreated),
            'exported' => self::date((int)$manifest['created']),
            'period' => self::period($manifest),
            'status' => get_string('status_' . $package->status, 'local_progressrecovery'),
            'isactive' => $package->status === import_manager::STATUS_VALIDATED,
            'compatibility' => get_string('compat_' . $package->compatibility, 'local_progressrecovery'),
            'badge' => self::BADGES[$package->compatibility],
            'users' => self::structure_count($manifest, 'user'),
            'courses' => self::structure_count($manifest, 'course'),
            'activities' => self::structure_count($manifest, 'course_modules'),
            'pending' => $pending,
        ];
    }

    /**
     * Detalhe de um pacote.
     *
     * @param \stdClass $package
     * @return array
     */
    public static function detail(\stdClass $package): array {
        $manifest = json_decode($package->manifest, true);
        $validation = json_decode($package->validation, true);
        $compatibility = $validation['compatibility'];

        $components = [];
        foreach ($compatibility['components'] as $name => $info) {
            $components[] = [
                'name' => get_string('component_' . $name, 'local_progressrecovery'),
                'rows' => $info['rows'],
                'status' => get_string('compat_' . $info['status'], 'local_progressrecovery'),
                'badge' => self::BADGES[$info['status']],
                'reasons' => self::reasons($info['reasons']),
            ];
        }
        $tables = [];
        foreach ($manifest['tables'] as $table => $info) {
            $counts = (array)($info['counts'] ?? []);
            $tables[] = [
                'table' => $table,
                'file' => $info['file'],
                'changed' => (int)($counts['changed'] ?? 0),
                'dependency' => (int)($counts['dependency'] ?? 0),
                'structure' => (int)($counts['structure'] ?? 0),
                'evidence' => (int)($counts['evidence'] ?? 0),
            ];
        }
        return self::summary($package) + [
            'identifier' => $package->source_identifier,
            'format' => $manifest['package_format'],
            'extractor' => $manifest['extractor_version'] ?? '',
            'timezone' => $manifest['timezone'],
            'prefix' => $manifest['table_prefix'],
            'global' => self::reasons($compatibility['global']),
            'hasglobal' => !empty($compatibility['global']),
            'components' => $components,
            'checks' => self::checks($validation['checks']),
            'haschecks' => !empty($validation['checks']),
            'tables' => $tables,
        ];
    }

    /**
     * Passos de validação, em texto.
     *
     * @param array $checks
     * @return array
     */
    public static function checks(array $checks): array {
        $out = [];
        foreach ($checks as $check) {
            $key = ($check['ok'] ? 'check_' : 'checkfail_') . $check['code'];
            $out[] = ['text' => get_string($key, 'local_progressrecovery', $check['a'] ?? ''), 'ok' => (bool)$check['ok']];
        }
        return $out;
    }

    /**
     * @param int $time
     * @return string
     */
    public static function date(int $time): string {
        return userdate($time, get_string('strftimedatetimeshort', 'langconfig'));
    }

    private static function reasons(array $reasons): array {
        $out = [];
        foreach ($reasons as $reason) {
            $out[] = ['text' => get_string('reason_' . $reason['code'], 'local_progressrecovery', $reason['a'] ?? '')];
        }
        return $out;
    }

    private static function period(array $manifest): string {
        $format = get_string('strftimedatefullshort', 'langconfig');
        return userdate($manifest['period']['start_epoch'], $format) . ' – '
            . userdate($manifest['period']['end_epoch_exclusive'] - 1, $format);
    }

    private static function structure_count(array $manifest, string $table): int {
        return (int)($manifest['tables'][$table]['counts']['structure'] ?? 0);
    }
}
