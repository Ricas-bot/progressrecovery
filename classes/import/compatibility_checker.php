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

namespace local_progressrecovery\import;

defined('MOODLE_INTERNAL') || die();

/**
 * Compatibilidade entre o pacote e esta instalação, por componente.
 *
 * Não depende da API do Totara: recebe o ambiente como array, para poder ser testada sem base de dados.
 * Um pacote incompatível fica registado, mas os componentes afectados ficam bloqueados nas fases seguintes.
 *
 * @package    local_progressrecovery
 * @copyright  2026 IT EDUdigital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class compatibility_checker {
    public const COMPATIBLE = 'compatible';
    public const WARNINGS = 'warnings';
    public const MANUAL = 'manual';
    public const INCOMPATIBLE = 'incompatible';
    private const RANK = [self::COMPATIBLE => 0, self::WARNINGS => 1, self::MANUAL => 2, self::INCOMPATIBLE => 3];

    /** Componentes cuja recuperação o cliente dispensou (P15), por não terem dados no período. */
    private const NOT_RECOVERED = ['quiz', 'assign'];

    /** Plugins de que cada componente depende: nomes exactos e prefixos. */
    private const PLUGINS = [
        'activity_completion' => [],
        'course_completion' => [],
        'grades' => [],
        'quiz' => ['mod_quiz', 'qtype_', 'qbehaviour_'],
        'assign' => ['mod_assign', 'assignsubmission_', 'assignfeedback_'],
        'scorm' => ['mod_scorm'],
        'feedback' => ['mod_feedback'],
        'customcert' => ['mod_customcert', 'customcertelement_', 'block_dedication'],
        'logs' => ['logstore_standard'],
    ];

    /** Tabelas com dados de progresso de cada componente. */
    private const TABLES = [
        'activity_completion' => ['course_modules_completion'],
        'course_completion' => ['course_completions', 'course_completion_crit_compl', 'course_completion_history'],
        'grades' => ['grade_grades'],
        'quiz' => ['quiz_attempts'],
        'assign' => ['assign_submission', 'assign_grades'],
        'scorm' => ['scorm_scoes_track', 'scorm_aicc_session'],
        'feedback' => ['feedback_completed'],
        'customcert' => ['customcert_issues'],
        'logs' => ['logstore_standard_log'],
    ];

    /**
     * @param array $manifest manifesto validado
     * @param array $environment totara_version, totara_build, core_version, site_identifier, timezone, plugins e log_stores
     * @return array overall, global (motivos) e components (status, rows, reasons)
     */
    public static function check(array $manifest, array $environment): array {
        $global = [];
        $totara = $manifest['totara'];
        if ($totara['totara_version'] !== $environment['totara_version']) {
            $global[] = self::reason(self::INCOMPATIBLE, 'totara_version', $totara['totara_version'] . ' / ' . $environment['totara_version']);
        } else if (($totara['totara_build'] ?? '') !== $environment['totara_build']) {
            $global[] = self::reason(self::WARNINGS, 'totara_build', ($totara['totara_build'] ?? '?') . ' / ' . $environment['totara_build']);
        }
        if ((string)$totara['core_version'] !== (string)$environment['core_version']) {
            $global[] = self::reason(self::INCOMPATIBLE, 'core_version', $totara['core_version'] . ' / ' . $environment['core_version']);
        }
        if ($manifest['source_identifier'] !== $environment['site_identifier']) {
            $global[] = self::reason(self::MANUAL, 'site_identifier', null);
        }
        if ($manifest['timezone'] !== $environment['timezone']) {
            $global[] = self::reason(self::WARNINGS, 'timezone', $manifest['timezone'] . ' / ' . $environment['timezone']);
        }

        $base = self::COMPATIBLE;
        foreach ($global as $reason) {
            $base = self::worst($base, $reason['status']);
        }

        $components = [];
        $overall = $base;
        foreach ($manifest['components'] as $component) {
            $rows = self::changed_rows($manifest, $component);
            $reasons = self::component_reasons($component, $rows, $manifest, $environment);
            $status = $base;
            foreach ($reasons as $reason) {
                $status = self::worst($status, $reason['status']);
            }
            $components[$component] = ['status' => $status, 'rows' => $rows, 'reasons' => $reasons];
            $overall = self::worst($overall, $status);
        }
        return ['overall' => $overall, 'global' => $global, 'components' => $components];
    }

    private static function component_reasons(string $component, int $rows, array $manifest, array $environment): array {
        if (in_array($component, self::NOT_RECOVERED, true)) {
            return $rows > 0 ? [self::reason(self::MANUAL, 'not_recovered', $rows)] : [self::reason(self::COMPATIBLE, 'no_data', null)];
        }
        $reasons = [];
        foreach ($manifest['plugins'] as $plugin => $version) {
            if (!self::plugin_belongs($plugin, $component)) {
                continue;
            }
            if (!isset($environment['plugins'][$plugin])) {
                $reasons[] = self::reason(self::INCOMPATIBLE, 'plugin_missing', $plugin);
            } else if ((string)$environment['plugins'][$plugin] !== (string)$version) {
                $reasons[] = self::reason(self::INCOMPATIBLE, 'plugin_version', $plugin . ': ' . $version . ' / ' . $environment['plugins'][$plugin]);
            }
        }
        if ($component === 'logs' && !in_array('logstore_standard', explode(',', (string)$environment['log_stores']), true)) {
            $reasons[] = self::reason(self::INCOMPATIBLE, 'log_store', null);
        }
        return $reasons;
    }

    private static function plugin_belongs(string $plugin, string $component): bool {
        foreach (self::PLUGINS[$component] ?? [] as $name) {
            if ($plugin === $name || (substr($name, -1) === '_' && strpos($plugin, $name) === 0)) {
                return true;
            }
        }
        return false;
    }

    private static function changed_rows(array $manifest, string $component): int {
        $rows = 0;
        foreach (self::TABLES[$component] ?? [] as $table) {
            $rows += (int)($manifest['tables'][$table]['counts']['changed'] ?? 0);
        }
        return $rows;
    }

    private static function reason(string $status, string $code, $a): array {
        return ['status' => $status, 'code' => $code, 'a' => $a];
    }

    private static function worst(string $a, string $b): string {
        return self::RANK[$a] >= self::RANK[$b] ? $a : $b;
    }
}
