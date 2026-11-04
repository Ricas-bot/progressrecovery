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
 * Comparação por lotes: um pedido de cada vez, retomável depois de uma interrupção.
 *
 * @module     local_progressrecovery/compare
 * @copyright  2026 IT EDUdigital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {
    var MAX_RETRIES = 5;

    return {
        init: function(stepurl, doneurl, jobid, sesskey) {
            var bar = document.getElementById('local-progressrecovery-compare-bar');
            var status = document.getElementById('local-progressrecovery-compare-status');
            var errorbox = document.getElementById('local-progressrecovery-compare-error');
            var resume = document.getElementById('local-progressrecovery-compare-resume');
            var label = status.textContent;

            var fail = function(message) {
                errorbox.textContent = message;
                errorbox.classList.remove('hidden');
                resume.classList.remove('hidden');
            };

            var step = function(retries) {
                var body = new URLSearchParams();
                body.append('job', jobid);
                body.append('sesskey', sesskey);
                fetch(stepurl, {method: 'POST', credentials: 'same-origin', body: body}).then(function(response) {
                    return response.json().then(function(json) {
                        return {code: response.status, json: json};
                    });
                }).then(function(result) {
                    if (result.code === 409 && result.json.busy) {
                        window.setTimeout(function() {
                            step(retries);
                        }, 3000);
                        return;
                    }
                    if (result.code !== 200) {
                        fail(result.json.error || ('HTTP ' + result.code));
                        return;
                    }
                    bar.style.width = result.json.percent + '%';
                    bar.setAttribute('aria-valuenow', result.json.percent);
                    status.textContent = label + ' ' + result.json.processed + ' / ' + result.json.total;
                    if (result.json.status === 'done') {
                        window.location.href = doneurl;
                    } else {
                        step(MAX_RETRIES);
                    }
                }).catch(function(error) {
                    // Falhas de rede: o lote interrompido é revertido, por isso repetir é seguro.
                    if (retries > 0) {
                        window.setTimeout(function() {
                            step(retries - 1);
                        }, 5000);
                    } else {
                        fail(error.message);
                    }
                });
            };

            resume.addEventListener('click', function() {
                errorbox.classList.add('hidden');
                resume.classList.add('hidden');
                step(MAX_RETRIES);
            });
            step(MAX_RETRIES);
        }
    };
});
