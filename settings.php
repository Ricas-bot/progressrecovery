<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Definições do plugin.
 *
 * @package     local_progressrecovery
 * @copyright   2026 IT EDUdigital <suporte@edudigital.pt>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_progressrecovery_settings',
        get_string('settings', 'local_progressrecovery'));

    // O valor nunca é escrito em claro no registo de configuração (admin_setting_configpasswordunmask).
    $settings->add(new admin_setting_configpasswordunmask(
        'local_progressrecovery/hmacsecret',
        get_string('hmacsecret', 'local_progressrecovery'),
        get_string('hmacsecret_desc', 'local_progressrecovery'),
        ''
    ));
    $settings->add(new admin_setting_configtext(
        'local_progressrecovery/maxpackagemb',
        get_string('maxpackagemb', 'local_progressrecovery'),
        get_string('maxpackagemb_desc', 'local_progressrecovery'),
        512,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_progressrecovery/copyepoch',
        get_string('copyepoch', 'local_progressrecovery'),
        get_string('copyepoch_desc', 'local_progressrecovery'),
        \local_progressrecovery\comparison\comparison_manager::DEFAULT_COPY_EPOCH,
        PARAM_INT
    ));
    $settings->add(new admin_setting_configtextarea(
        'local_progressrecovery/excludedusers',
        get_string('excludedusers', 'local_progressrecovery'),
        get_string('excludedusers_desc', 'local_progressrecovery'),
        "pedro.edu\nadmin"
    ));
    // Desligada por omissão: instalar o plugin nunca permite, por si só, alterar dados académicos.
    $settings->add(new admin_setting_configcheckbox(
        'local_progressrecovery/allowrecovery',
        get_string('allowrecovery', 'local_progressrecovery'),
        get_string('allowrecovery_desc', 'local_progressrecovery'),
        0
    ));

    $ADMIN->add('localplugins', $settings);
}

if ($hassiteconfig || has_capability('local/progressrecovery:manage', context_system::instance())) {
    $ADMIN->add('localplugins', new admin_externalpage(
        'local_progressrecovery',
        get_string('pluginname', 'local_progressrecovery'),
        new moodle_url('/local/progressrecovery/index.php'),
        'local/progressrecovery:manage'
    ));
}
