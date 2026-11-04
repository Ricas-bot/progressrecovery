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

defined('MOODLE_INTERNAL') || die();

/**
 * Autenticidade do pacote: HMAC-SHA256 com o segredo partilhado com o Extractor.
 *
 * A assinatura cobre o manifesto e a lista de checksums, que por sua vez cobre todos os ficheiros.
 *
 * @package    local_progressrecovery
 * @copyright  2026 IT EDUdigital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class signature_validator {
    /**
     * @param string $manifest conteúdo de manifest.json
     * @param string $integrity conteúdo de integrity.json
     * @param string $signaturejson conteúdo de signature.json
     * @param string $secret
     * @return bool
     */
    public static function is_valid(string $manifest, string $integrity, string $signaturejson, string $secret): bool {
        $signature = json_decode($signaturejson, true);
        if ($secret === '' || !is_array($signature) || ($signature['algorithm'] ?? '') !== 'HMAC-SHA256'
                || !is_string($signature['signature'] ?? null)) {
            return false;
        }
        $expected = hash_hmac('sha256', "TRP1\n" . hash('sha256', $manifest) . "\n" . hash('sha256', $integrity), $secret);
        return hash_equals($expected, $signature['signature']);
    }
}
