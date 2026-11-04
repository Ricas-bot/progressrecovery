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
 * Detalhe de um pacote: manifesto, integridade, compatibilidade e remoção.
 *
 * @package    local_progressrecovery
 * @copyright  2026 IT EDUdigital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_progressrecovery\import\import_manager;
use local_progressrecovery\output\package_view;

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

$id = required_param('id', PARAM_INT);
$delete = optional_param('delete', 0, PARAM_BOOL);
$confirm = optional_param('confirm', 0, PARAM_BOOL);

$url = new moodle_url('/local/progressrecovery/package.php', ['id' => $id]);
admin_externalpage_setup('local_progressrecovery', '', null, $url);
require_capability('local/progressrecovery:manage', context_system::instance());

$package = import_manager::get_package($id);
$isactive = $package->status === import_manager::STATUS_VALIDATED;

$PAGE->set_title($package->filename);
$PAGE->set_heading(get_string('pluginname', 'local_progressrecovery'));

if ($delete && $isactive && $confirm) {
    require_sesskey();
    import_manager::delete_package($package);
    redirect(new moodle_url('/local/progressrecovery/index.php'),
        get_string('packagedeleted', 'local_progressrecovery'), null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo $OUTPUT->heading($package->filename);

if ($delete && $isactive) {
    echo $OUTPUT->confirm(
        get_string('deleteconfirm', 'local_progressrecovery', $package->filename),
        new moodle_url($url, ['delete' => 1, 'confirm' => 1]),
        $url
    );
    echo $OUTPUT->footer();
    die();
}

$context = package_view::detail($package);
$context['indexurl'] = (new moodle_url('/local/progressrecovery/index.php'))->out(false);
$context['deleteurl'] = (new moodle_url($url, ['delete' => 1]))->out(false);
$context['compareurl'] = (new moodle_url('/local/progressrecovery/compare.php', ['id' => $id]))->out(false);
echo $OUTPUT->render_from_template('local_progressrecovery/package_detail', $context);
echo $OUTPUT->footer();
