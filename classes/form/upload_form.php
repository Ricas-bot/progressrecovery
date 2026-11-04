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

namespace local_progressrecovery\form;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * Formulário de carregamento do pacote.
 *
 * @package    local_progressrecovery
 * @copyright  2026 IT EDUdigital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class upload_form extends \moodleform {
    protected function definition() {
        $mform = $this->_form;
        $mform->addElement('filepicker', 'package', get_string('packagefile', 'local_progressrecovery'), null, [
            'accepted_types' => ['.zip'],
            'maxbytes' => $this->_customdata['maxbytes'],
        ]);
        $mform->addRule('package', null, 'required');
        $this->add_action_buttons(true, get_string('uploadvalidate', 'local_progressrecovery'));
    }
}
