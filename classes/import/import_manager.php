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

use local_progressrecovery\audit\audit_manager;
use local_progressrecovery\security\package_validator;
use local_progressrecovery\recovery\recovery_manager;

defined('MOODLE_INTERNAL') || die();

/**
 * Recepção dos pacotes: validação, armazenamento privado e histórico.
 *
 * Nesta fase nenhuma tabela académica é alterada: só as tabelas do próprio plugin.
 *
 * @package    local_progressrecovery
 * @copyright  2026 IT EDUdigital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class import_manager {
    public const STATUS_VALIDATED = 'validated';
    public const STATUS_DELETED = 'deleted';
    private const TABLE = 'local_progressrecovery_packages';
    private const DEFAULT_MAX_MB = 512;

    /**
     * Pasta privada dos pacotes, dentro de dataroot (fora do webroot).
     *
     * @return string
     */
    public static function storage_dir(): string {
        global $CFG;
        return make_writable_directory($CFG->dataroot . '/local_progressrecovery/packages');
    }

    /**
     * @return bool se o segredo partilhado está configurado
     */
    public static function has_secret(): bool {
        return (string)get_config('local_progressrecovery', 'hmacsecret') !== '';
    }

    /**
     * @return int tamanho máximo do pacote, em bytes
     */
    public static function max_bytes(): int {
        $mb = (int)get_config('local_progressrecovery', 'maxpackagemb');
        return ($mb > 0 ? $mb : self::DEFAULT_MAX_MB) * 1048576;
    }

    /**
     * @return array ambiente desta instalação, para a compatibilidade
     */
    public static function environment(): array {
        global $DB;
        return [
            'totara_version' => (string)get_config('core', 'totara_version'),
            'totara_build' => (string)get_config('core', 'totara_build'),
            'core_version' => (string)get_config('core', 'version'),
            'site_identifier' => (string)get_site_identifier(),
            'timezone' => (string)get_config('core', 'timezone'),
            'plugins' => $DB->get_records_menu('config_plugins', ['name' => 'version'], '', 'plugin, value'),
            'log_stores' => (string)get_config('tool_log', 'enabled_stores'),
        ];
    }

    /**
     * Valida um ficheiro carregado e, se for aceite, guarda-o na área privada.
     *
     * @param string $path ficheiro temporário
     * @param string $filename nome original
     * @return array accepted, checks, error (código) e id
     */
    public static function process_upload(string $path, string $filename, ?array $environment = null): array {
        global $DB, $USER;

        $filename = clean_param($filename, PARAM_FILE);
        $validator = new package_validator((string)get_config('local_progressrecovery', 'hmacsecret'), self::max_bytes());
        $result = $validator->validate($path, $filename);

        if (!$result['valid']) {
            $failed = end($result['checks']);
            audit_manager::log('package_rejected', audit_manager::RESULT_REJECTED, [
                'filename' => $filename,
                'check' => $failed['code'] ?? null,
                'detail' => $failed['a'] ?? null,
            ]);
            return ['accepted' => false, 'checks' => $result['checks'], 'error' => null, 'id' => null];
        }

        $manifest = $result['manifest'];
        $uuid = $manifest['package_uuid'];
        $existing = $DB->get_record(self::TABLE, ['package_uuid' => $uuid]);
        if (!$existing) {
            $same = $DB->get_records(self::TABLE, ['package_hash' => $result['sha256'], 'status' => self::STATUS_VALIDATED], '', 'id', 0, 1);
            $existing = $same ? $DB->get_record(self::TABLE, ['id' => reset($same)->id]) : false;
        }
        if ($existing && $existing->status === self::STATUS_VALIDATED) {
            audit_manager::log('package_duplicate', audit_manager::RESULT_REJECTED, ['filename' => $filename, 'uuid' => $uuid], (int)$existing->id);
            return ['accepted' => false, 'checks' => $result['checks'], 'error' => 'duplicate', 'id' => (int)$existing->id];
        }

        $compatibility = compatibility_checker::check($manifest, $environment ?? self::environment());
        $target = self::package_path($uuid);
        if (!copy($path, $target)) {
            throw new \moodle_exception('error_storage', 'local_progressrecovery');
        }
        @chmod($target, 0600);
        if (!hash_equals($result['sha256'], (string)hash_file('sha256', $target))) {
            @unlink($target);
            throw new \moodle_exception('error_storage', 'local_progressrecovery');
        }

        $now = time();
        $record = (object)[
            'package_uuid' => $uuid,
            'filename' => $filename,
            'package_hash' => $result['sha256'],
            'filesize' => $result['bytes'],
            'source_version' => $manifest['totara']['totara_version'],
            'source_identifier' => $manifest['source_identifier'],
            'extraction_start' => $manifest['period']['start_epoch'],
            'extraction_end' => $manifest['period']['end_epoch_exclusive'],
            'status' => self::STATUS_VALIDATED,
            'compatibility' => $compatibility['overall'],
            'manifest' => $result['manifest_raw'],
            'validation' => json_encode(['checks' => $result['checks'], 'compatibility' => $compatibility], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'userid' => (int)$USER->id,
            'timemodified' => $now,
        ];
        try {
            if ($existing) {
                // Pacote apagado antes e carregado de novo, por decisão explícita do administrador.
                $record->id = $existing->id;
                $DB->update_record(self::TABLE, $record);
                $id = (int)$existing->id;
            } else {
                $record->timecreated = $now;
                $id = (int)$DB->insert_record(self::TABLE, $record);
            }
        } catch (\Throwable $e) {
            @unlink($target);
            throw $e;
        }
        audit_manager::log('package_accepted', audit_manager::RESULT_OK, [
            'filename' => $filename,
            'uuid' => $uuid,
            'sha256' => $result['sha256'],
            'compatibility' => $compatibility['overall'],
        ], $id);
        return ['accepted' => true, 'checks' => $result['checks'], 'error' => null, 'id' => $id];
    }

    /**
     * Apaga o ficheiro do pacote. O registo fica no histórico.
     *
     * @param \stdClass $package
     */
    public static function delete_package(\stdClass $package): void {
        global $DB;
        // Uma recuperação ou uma compensação a meio ainda lê o pacote.
        if (recovery_manager::is_running((int)$package->id)) {
            throw new \moodle_exception('error_recovery_running', 'local_progressrecovery');
        }
        $path = self::package_path($package->package_uuid);
        if (is_file($path)) {
            unlink($path);
        }
        $DB->update_record(self::TABLE, (object)[
            'id' => $package->id,
            'status' => self::STATUS_DELETED,
            'timemodified' => time(),
        ]);
        audit_manager::log('package_deleted', audit_manager::RESULT_OK, ['uuid' => $package->package_uuid], (int)$package->id);
    }

    /**
     * @param int $id
     * @return \stdClass
     */
    public static function get_package(int $id): \stdClass {
        global $DB;
        return $DB->get_record(self::TABLE, ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * @param int $limit
     * @return \stdClass[] do mais recente para o mais antigo
     */
    public static function packages(int $limit = 50): array {
        global $DB;
        return array_values($DB->get_records(self::TABLE, null, 'timemodified DESC, id DESC', '*', 0, $limit));
    }

    /**
     * @param string $uuid
     * @return string
     */
    public static function package_path(string $uuid): string {
        // O nome no disco vem do uuid validado, nunca do nome enviado pelo browser.
        return self::storage_dir() . '/' . clean_param($uuid, PARAM_ALPHANUMEXT) . '.zip';
    }
}
