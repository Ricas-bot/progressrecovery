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
 * Painel: estado do último pacote e histórico.
 *
 * @package    local_progressrecovery
 * @copyright  2026 IT EDUdigital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_progressrecovery\audit\audit_manager;
use local_progressrecovery\import\import_manager;
use local_progressrecovery\output\package_view;

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_progressrecovery');
require_capability('local/progressrecovery:manage', context_system::instance());

$PAGE->set_title(get_string('pluginname', 'local_progressrecovery'));
$PAGE->set_heading(get_string('pluginname', 'local_progressrecovery'));

$packages = array_map([package_view::class, 'summary'], import_manager::packages());
$latest = null;
foreach ($packages as $package) {
    if ($package['isactive']) {
        $latest = $package;
        break;
    }
}

$rejections = [];
foreach (audit_manager::recent(10, null, audit_manager::RESULT_REJECTED) as $entry) {
    $details = json_decode((string)$entry->details, true) ?: [];
    $rejections[] = [
        'date' => package_view::date((int)$entry->timecreated),
        'action' => get_string('audit_' . $entry->action, 'local_progressrecovery'),
        'filename' => $details['filename'] ?? '',
        'check' => isset($details['check'])
            ? get_string('checkfail_' . $details['check'], 'local_progressrecovery', $details['detail'] ?? '') : '',
    ];
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('pluginname', 'local_progressrecovery'));
if (!import_manager::has_secret()) {
    echo $OUTPUT->notification(get_string('nosecret', 'local_progressrecovery'), 'notifyproblem');
}
echo $OUTPUT->render_from_template('local_progressrecovery/dashboard', [
    'latest' => $latest,
    'haslatest' => $latest !== null,
    'packages' => $packages,
    'haspackages' => !empty($packages),
    'rejections' => $rejections,
    'hasrejections' => !empty($rejections),
    'uploadurl' => (new moodle_url('/local/progressrecovery/upload.php'))->out(false),
]);
echo $OUTPUT->footer();
