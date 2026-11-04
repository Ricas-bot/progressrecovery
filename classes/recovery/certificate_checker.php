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
 * Confirma que um formando consegue obter o certificado recuperado: repete as duas verificações de tempo do
 * mod/customcert/view.php e gera o PDF com a API do módulo, sem o enviar nem guardar. Não escreve nada.
 *
 * @package    local_progressrecovery
 * @copyright  2026 IT EDUdigital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class certificate_checker {
    public const PDF_FAILED = 'pdf_failed';
    public const TIME_SHORT = 'time_short';

    /**
     * @param int $issueid emissão no destino
     * @return array|null null se estiver tudo bem; senão reason e detail
     */
    public static function check(int $issueid): ?array {
        global $DB;
        try {
            $issue = $DB->get_record('customcert_issues', ['id' => $issueid], '*', MUST_EXIST);
            $customcert = $DB->get_record('customcert', ['id' => $issue->customcertid], '*', MUST_EXIST);
            $template = $DB->get_record('customcert_templates', ['id' => $customcert->templateid], '*', MUST_EXIST);
            self::generate((int)$issue->userid, $template);
            return self::check_time($customcert, (int)$issue->userid);
        } catch (\Throwable $e) {
            return ['reason' => self::PDF_FAILED, 'detail' => mb_substr($e->getMessage(), 0, 100)];
        }
    }

    /**
     * O PDF não é guardado pelo módulo: é gerado em cada download, com os dados actuais do destino.
     */
    private static function generate(int $userid, \stdClass $template): void {
        ob_start();
        try {
            $pdf = (new \mod_customcert\template($template))->generate_pdf(false, $userid, true);
        } finally {
            ob_end_clean();
        }
        if (!is_string($pdf) || strpos($pdf, '%PDF-') !== 0) {
            throw new \moodle_exception('error_recovery_pdf', 'local_progressrecovery');
        }
    }

    /**
     * As mesmas condições do view.php: quem gere o certificado não é verificado, e os dois tempos são exigidos.
     */
    private static function check_time(\stdClass $customcert, int $userid): ?array {
        global $CFG, $DB;
        $required = (int)$customcert->requiredtime * 60;
        if ($required <= 0) {
            return null;
        }
        $cmid = $DB->get_field_sql('SELECT cm.id FROM {course_modules} cm JOIN {modules} m ON m.id = cm.module WHERE m.name = ? AND cm.instance = ?',
            ['customcert', (int)$customcert->id], MUST_EXIST);
        if (has_capability('mod/customcert:manage', \context_module::instance((int)$cmid), $userid)) {
            return null;
        }
        if (!class_exists('block_dedication_manager', false)) {
            require_once($CFG->dirroot . '/blocks/dedication/dedication_lib.php');
        }
        $logs = (int)\mod_customcert\certificate::get_course_time((int)$customcert->course, $userid);
        $times = $DB->get_records_sql("SELECT t.id, t.value
                                         FROM {scorm_scoes_track} t
                                         JOIN {scorm} s ON s.id = t.scormid
                                        WHERE s.course = ? AND t.userid = ? AND t.element IN (?, ?)",
            [(int)$customcert->course, $userid, 'cmi.core.total_time', 'cmi.total_time']);
        $scorm = (int)\block_dedication_manager::sumTime($times, true);
        if ($logs >= $required && $scorm >= $required) {
            return null;
        }
        return ['reason' => self::TIME_SHORT, 'detail' => "logs {$logs} s, SCORM {$scorm} s, exigido {$required} s"];
    }
}
