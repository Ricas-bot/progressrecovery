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

namespace local_progressrecovery\security;

use local_progressrecovery\import\package_reader;

defined('MOODLE_INTERNAL') || die();

/**
 * Validação de um pacote carregado. Pára na primeira falha.
 *
 * Ordem: forma do ficheiro → arquivo seguro → assinatura → checksums → manifesto → linhas.
 * Só o manifesto, os checksums e a assinatura são lidos antes de a assinatura estar confirmada.
 * Esta classe não depende da API do Totara, para poder ser testada sem base de dados.
 *
 * @package    local_progressrecovery
 * @copyright  2026 IT EDUdigital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class package_validator {
    public const FORMAT = 1;
    private const MAX_ENTRIES = 200;
    private const MAX_SMALL_FILE = 16777216;
    private const MAX_LINE = 33554432;
    private const MAX_RATIO = 1000;
    private const RATIO_MIN_BYTES = 10485760;
    private const ROLES = ['changed', 'dependency', 'structure', 'evidence'];
    private const METADATA_FILES = [
        'manifest.json',
        'metadata/source.json',
        'metadata/version.json',
        'metadata/plugins.json',
        'metadata/schema.json',
        'metadata/extraction.json',
    ];
    private const COMPONENTS = ['activity_completion', 'course_completion', 'grades', 'quiz', 'assign', 'scorm',
        'feedback', 'customcert', 'logs'];

    /** Tabelas aceites no formato 1 e respectivo ficheiro. Nenhuma outra é lida. */
    public const TABLE_FILES = [
        'course_modules_completion' => 'completions/activity_completions.ndjson',
        'course_completions' => 'completions/course_completions.ndjson',
        'course_completion_crit_compl' => 'completions/criteria_completions.ndjson',
        'course_completion_history' => 'completions/course_history.ndjson',
        'course_completion_log' => 'completions/completion_log.ndjson',
        'course_completion_criteria' => 'completions/criteria.ndjson',
        'course_completion_aggr_methd' => 'completions/aggregation.ndjson',
        'grade_grades' => 'grades/grade_grades.ndjson',
        'grade_grades_history' => 'grades/grade_history.ndjson',
        'grade_items' => 'grades/grade_items.ndjson',
        'grade_categories' => 'grades/grade_categories.ndjson',
        'quiz_attempts' => 'quiz/attempts.ndjson',
        'quiz_grades' => 'quiz/grades.ndjson',
        'question_usages' => 'quiz/usages.ndjson',
        'question_attempts' => 'quiz/question_attempts.ndjson',
        'question_attempt_steps' => 'quiz/steps.ndjson',
        'question_attempt_step_data' => 'quiz/step_data.ndjson',
        'quiz' => 'quiz/instances.ndjson',
        'quiz_slots' => 'quiz/slots.ndjson',
        'question' => 'quiz/questions.ndjson',
        'assign_submission' => 'assignments/submissions.ndjson',
        'assign_grades' => 'assignments/grades.ndjson',
        'assign_user_flags' => 'assignments/user_flags.ndjson',
        'assign_user_mapping' => 'assignments/user_mapping.ndjson',
        'assignsubmission_onlinetext' => 'assignments/onlinetext.ndjson',
        'assignsubmission_file' => 'assignments/submission_files_meta.ndjson',
        'assignfeedback_comments' => 'assignments/feedback_comments.ndjson',
        'assignfeedback_file' => 'assignments/feedback_files_meta.ndjson',
        'assign' => 'assignments/instances.ndjson',
        'scorm_scoes_track' => 'scorm/tracking.ndjson',
        'scorm_aicc_session' => 'scorm/aicc_sessions.ndjson',
        'scorm' => 'scorm/instances.ndjson',
        'scorm_scoes' => 'scorm/scoes.ndjson',
        'feedback_completed' => 'feedback/completions.ndjson',
        'feedback_value' => 'feedback/values.ndjson',
        'feedback' => 'feedback/instances.ndjson',
        'feedback_item' => 'feedback/items.ndjson',
        'customcert_issues' => 'certificates/issues.ndjson',
        'customcert' => 'certificates/instances.ndjson',
        'customcert_templates' => 'certificates/templates.ndjson',
        'customcert_pages' => 'certificates/pages.ndjson',
        'customcert_elements' => 'certificates/elements.ndjson',
        'files' => 'certificates/files.ndjson',
        'logstore_standard_log' => 'logs/logstore.ndjson',
        'context' => 'courses/contexts.ndjson',
        'course_modules' => 'courses/activities.ndjson',
        'course' => 'courses/courses.ndjson',
        'user_enrolments' => 'enrolments/enrolments.ndjson',
        'user' => 'users/users.ndjson',
    ];

    /** @var string */
    private $secret;
    /** @var int */
    private $maxbytes;
    /** @var array */
    private $checks = [];

    /**
     * @param string $secret segredo partilhado com o Extractor
     * @param int $maxbytes tamanho máximo do ZIP; o conteúdo descomprimido pode ser até 4 vezes maior
     */
    public function __construct(string $secret, int $maxbytes) {
        $this->secret = $secret;
        $this->maxbytes = $maxbytes;
    }

    /**
     * @param string $path ficheiro carregado
     * @param string $filename nome original
     * @return array valid, checks (code, ok, a), manifest, sha256 e bytes
     */
    public function validate(string $path, string $filename): array {
        $this->checks = [];
        $result = ['valid' => false, 'manifest' => null, 'sha256' => null, 'bytes' => null];
        $reader = new package_reader();
        try {
            if (!$this->check_file($path, $filename, $reader)) {
                return $result + ['checks' => $this->checks];
            }
            $entries = $reader->entries();
            if (!$this->check_archive($entries)) {
                return $result + ['checks' => $this->checks];
            }
            $manifestraw = $reader->read('manifest.json', self::MAX_SMALL_FILE);
            $integrityraw = $reader->read('integrity.json', self::MAX_SMALL_FILE);
            $signatureraw = $reader->read('signature.json', 65536);
            if ($manifestraw === null || $integrityraw === null || $signatureraw === null) {
                $this->add('required_files', false);
                return $result + ['checks' => $this->checks];
            }
            $this->add('required_files', true);

            if ($this->secret === '') {
                $this->add('secret_missing', false);
                return $result + ['checks' => $this->checks];
            }
            if (!signature_validator::is_valid($manifestraw, $integrityraw, $signatureraw, $this->secret)) {
                $this->add('signature', false);
                return $result + ['checks' => $this->checks];
            }
            $this->add('signature', true);

            $integrity = json_decode($integrityraw, true);
            if (!$this->check_checksums($reader, $entries, $integrity)) {
                return $result + ['checks' => $this->checks];
            }
            $manifest = json_decode($manifestraw, true);
            if (!$this->check_manifest($manifest, $integrity)) {
                return $result + ['checks' => $this->checks];
            }
            if (!$this->check_rows($reader, $manifest, $integrity)) {
                return $result + ['checks' => $this->checks];
            }
            $result['valid'] = true;
            $result['manifest'] = $manifest;
            $result['manifest_raw'] = $manifestraw;
            $result['sha256'] = hash_file('sha256', $path);
            $result['bytes'] = filesize($path);
            return $result + ['checks' => $this->checks];
        } finally {
            $reader->close();
        }
    }

    /**
     * Extensão, tipo real e tamanho do ficheiro.
     */
    private function check_file(string $path, string $filename, package_reader $reader): bool {
        if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'zip') {
            $this->add('extension', false);
            return false;
        }
        $this->add('extension', true);

        $size = @filesize($path);
        if ($size === false || $size === 0 || $size > $this->maxbytes) {
            $this->add('size', false, $this->maxbytes);
            return false;
        }
        $this->add('size', true);

        $fh = fopen($path, 'rb');
        $magic = $fh ? fread($fh, 4) : '';
        if ($fh) {
            fclose($fh);
        }
        if ($magic !== "PK\x03\x04" || !$reader->open($path)) {
            $this->add('real_type', false);
            return false;
        }
        $this->add('real_type', true);
        return true;
    }

    /**
     * Nomes seguros, sem ligações simbólicas, cifra nem expansão desproporcionada.
     */
    private function check_archive(array $entries): bool {
        if (!$entries || count($entries) > self::MAX_ENTRIES) {
            $this->add('archive_entries', false);
            return false;
        }
        $seen = [];
        $total = 0;
        foreach ($entries as $entry) {
            $name = $entry['name'];
            $relative = strpos($name, package_reader::ROOT) === 0 ? substr($name, strlen(package_reader::ROOT)) : null;
            if ($relative === null || $relative === '' || strpos($name, "\0") !== false || strpos($name, '\\') !== false
                    || !preg_match('#^[a-z0-9_]+(/[a-z0-9_]+)*(\.(json|ndjson))$#', $relative)) {
                $this->add('archive_names', false, $name);
                return false;
            }
            if (isset($seen[$name])) {
                $this->add('archive_names', false, $name);
                return false;
            }
            $seen[$name] = true;
            if ($entry['symlink'] || $entry['encrypted']) {
                $this->add('archive_special', false, $name);
                return false;
            }
            $total += $entry['size'];
            if ($entry['size'] > self::RATIO_MIN_BYTES && $entry['size'] > $entry['compressed'] * self::MAX_RATIO) {
                $this->add('archive_bomb', false, $name);
                return false;
            }
        }
        if ($total > $this->maxbytes * 4) {
            $this->add('archive_bomb', false, $total);
            return false;
        }
        $this->add('archive_names', true);
        return true;
    }

    /**
     * Cada ficheiro do pacote tem de constar da lista assinada, e vice-versa.
     */
    private function check_checksums(package_reader $reader, array $entries, $integrity): bool {
        if (!is_array($integrity) || ($integrity['algorithm'] ?? '') !== 'sha256' || !is_array($integrity['files'] ?? null)) {
            $this->add('checksums', false, 'integrity.json');
            return false;
        }
        $present = [];
        foreach ($entries as $entry) {
            $present[substr($entry['name'], strlen(package_reader::ROOT))] = true;
        }
        unset($present['integrity.json'], $present['signature.json']);
        foreach (array_keys($present) as $name) {
            if (!isset($integrity['files'][$name])) {
                $this->add('unlisted_file', false, $name);
                return false;
            }
        }
        foreach ($integrity['files'] as $name => $info) {
            if (!isset($present[$name])) {
                $this->add('checksums', false, $name);
                return false;
            }
            $hash = $reader->hash($name);
            if ($hash === null || !is_array($info) || !hash_equals((string)($info['sha256'] ?? ''), $hash['sha256'])
                    || (int)($info['bytes'] ?? -1) !== $hash['bytes']) {
                $this->add('checksums', false, $name);
                return false;
            }
        }
        $this->add('checksums', true, count($integrity['files']));
        return true;
    }

    private function check_manifest($manifest, array $integrity): bool {
        if (!is_array($manifest)) {
            $this->add('manifest', false, 'json');
            return false;
        }
        if (($manifest['package_format'] ?? null) !== self::FORMAT) {
            $this->add('manifest', false, 'package_format');
            return false;
        }
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', (string)($manifest['package_uuid'] ?? ''))) {
            $this->add('manifest', false, 'package_uuid');
            return false;
        }
        foreach (['source_identifier', 'timezone', 'table_prefix'] as $field) {
            if (!is_string($manifest[$field] ?? null) || $manifest[$field] === '' || strlen($manifest[$field]) > 255) {
                $this->add('manifest', false, $field);
                return false;
            }
        }
        foreach (['totara_version', 'core_version'] as $field) {
            if (!is_string($manifest['totara'][$field] ?? null)) {
                $this->add('manifest', false, $field);
                return false;
            }
        }
        $start = $manifest['period']['start_epoch'] ?? null;
        $end = $manifest['period']['end_epoch_exclusive'] ?? null;
        if (!is_int($start) || !is_int($end) || $start <= 0 || $end <= $start) {
            $this->add('manifest', false, 'period');
            return false;
        }
        if (!is_array($manifest['plugins'] ?? null)) {
            $this->add('manifest', false, 'plugins');
            return false;
        }
        $components = $manifest['components'] ?? null;
        if (!is_array($components) || !$components || array_diff($components, self::COMPONENTS)) {
            $this->add('manifest', false, 'components');
            return false;
        }
        if (!is_array($manifest['tables'] ?? null) || !$manifest['tables']) {
            $this->add('manifest', false, 'tables');
            return false;
        }
        $allowed = array_flip(self::METADATA_FILES);
        foreach ($manifest['tables'] as $table => $info) {
            if (!isset(self::TABLE_FILES[$table]) || ($info['file'] ?? null) !== self::TABLE_FILES[$table]
                    || !isset($integrity['files'][self::TABLE_FILES[$table]])) {
                $this->add('table_not_allowed', false, $table);
                return false;
            }
            $allowed[$info['file']] = true;
        }
        foreach (array_keys($integrity['files']) as $file) {
            if (!isset($allowed[$file])) {
                $this->add('unlisted_file', false, $file);
                return false;
            }
        }
        $this->add('manifest', true);
        return true;
    }

    /**
     * Cada linha é JSON, pertence à tabela do ficheiro e as contagens batem certo com o manifesto.
     */
    private function check_rows(package_reader $reader, array $manifest, array $integrity): bool {
        foreach ($manifest['tables'] as $table => $info) {
            $file = $info['file'];
            $count = 0;
            foreach ($reader->lines($file, self::MAX_LINE) as $line) {
                $row = $line === false ? null : json_decode($line, true);
                if (!is_array($row) || ($row['t'] ?? null) !== $table || !in_array($row['role'] ?? null, self::ROLES, true)
                        || !is_array($row['d'] ?? null) || !is_string($row['h'] ?? null) || !preg_match('/^[0-9a-f]{64}$/', $row['h'])
                        || !(is_int($row['sid'] ?? null) || (array_key_exists('sid', $row) && $row['sid'] === null))) {
                    $this->add('rows', false, $file . ':' . ($count + 1));
                    return false;
                }
                $count++;
            }
            $declared = array_sum(array_map('intval', (array)($info['counts'] ?? [])));
            if ($count !== $declared || $count !== (int)($integrity['files'][$file]['lines'] ?? -1)) {
                $this->add('rows', false, $file);
                return false;
            }
        }
        $this->add('rows', true);
        return true;
    }

    /**
     * @param string $code identificador (strings check_<code> e checkfail_<code>)
     * @param bool $ok
     * @param mixed $a detalhe para a mensagem
     */
    private function add(string $code, bool $ok, $a = null): void {
        $this->checks[] = ['code' => $code, 'ok' => $ok, 'a' => $a];
    }
}
