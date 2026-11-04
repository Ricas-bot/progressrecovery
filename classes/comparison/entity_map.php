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

namespace local_progressrecovery\comparison;

defined('MOODLE_INTERNAL') || die();

/**
 * Correspondência entre entidades da origem e do destino, gravada por entity_matcher.
 *
 * @package    local_progressrecovery
 * @copyright  2026 IT EDUdigital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class entity_map {
    public const TABLE = 'local_progressrecovery_entities';
    public const OK = 'ok';
    public const REMAPPED = 'remapped';
    public const MISSING = 'missing';
    public const EXCLUDED = 'excluded';
    public const MANUAL = 'manual';
    public const INVALID = 'invalid';

    /** @var int */
    private $jobid;
    /** @var array */
    private $cache = [];

    /**
     * @param int $jobid
     */
    public function __construct(int $jobid) {
        $this->jobid = $jobid;
    }

    /**
     * Carrega várias entidades de uma vez, para evitar uma consulta por linha.
     *
     * @param string $entity
     * @param int[] $ids
     */
    public function preload(string $entity, array $ids): void {
        global $DB;
        $missing = [];
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            if ($id > 0 && !array_key_exists($id, $this->cache[$entity] ?? [])) {
                $missing[] = $id;
                $this->cache[$entity][$id] = null;
            }
        }
        foreach (array_chunk($missing, 500) as $chunk) {
            list($in, $params) = $DB->get_in_or_equal($chunk);
            $rows = $DB->get_records_select(self::TABLE, "jobid = ? AND entity = ? AND source_id $in",
                array_merge([$this->jobid, $entity], $params));
            foreach ($rows as $row) {
                $this->cache[$entity][(int)$row->source_id] = $row;
            }
        }
    }

    /**
     * @param string $entity
     * @param int $id identificador na origem
     * @return \stdClass|null
     */
    public function get(string $entity, int $id): ?\stdClass {
        if (!array_key_exists($id, $this->cache[$entity] ?? [])) {
            $this->preload($entity, [$id]);
        }
        return $this->cache[$entity][$id] ?? null;
    }

    /**
     * @param \stdClass|null $entry
     * @return bool se a entidade existe no destino e pode ser usada
     */
    public static function usable(?\stdClass $entry): bool {
        return $entry !== null && in_array($entry->status, [self::OK, self::REMAPPED], true);
    }
}
