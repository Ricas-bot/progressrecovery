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

defined('MOODLE_INTERNAL') || die();

/**
 * Leitura de um pacote sem o extrair para o disco.
 *
 * Todo o conteúdo é lido por streams a partir do ZIP. Como nada é escrito no disco com os nomes
 * do arquivo, um nome malicioso (ZIP Slip) não tem efeito; ainda assim é rejeitado na validação.
 *
 * @package    local_progressrecovery
 * @copyright  2026 IT EDUdigital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class package_reader {
    /** Pasta raiz de todas as entradas do pacote. */
    public const ROOT = 'totara-recovery-package/';

    /** @var \ZipArchive */
    private $zip;

    /**
     * @param string $path
     * @return bool se o ficheiro abriu como ZIP
     */
    public function open(string $path): bool {
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
            return false;
        }
        $this->zip = $zip;
        return true;
    }

    public function close(): void {
        if ($this->zip) {
            $this->zip->close();
            $this->zip = null;
        }
    }

    /**
     * Entradas do arquivo, tal como estão no índice.
     *
     * @return array[] name, size, compressed, encrypted e symlink
     */
    public function entries(): array {
        $entries = [];
        for ($i = 0; $i < $this->zip->numFiles; $i++) {
            $stat = $this->zip->statIndex($i, \ZipArchive::FL_UNCHANGED);
            $opsys = 0;
            $attributes = 0;
            $this->zip->getExternalAttributesIndex($i, $opsys, $attributes);
            // Em arquivos Unix, os 16 bits superiores são o modo do ficheiro; 0120000 é uma ligação simbólica.
            $symlink = $opsys === \ZipArchive::OPSYS_UNIX && (($attributes >> 16) & 0170000) === 0120000;
            $entries[] = [
                'name' => (string)$stat['name'],
                'size' => (int)$stat['size'],
                'compressed' => (int)$stat['comp_size'],
                'encrypted' => !empty($stat['encryption_method']),
                'symlink' => $symlink,
            ];
        }
        return $entries;
    }

    /**
     * @param string $name caminho relativo à raiz do pacote
     * @param int $maxbytes
     * @return string|null conteúdo, ou null se não existir ou exceder o limite
     */
    public function read(string $name, int $maxbytes): ?string {
        $fullname = self::ROOT . $name;
        $stat = $this->zip->statName($fullname);
        if ($stat === false || $stat['size'] > $maxbytes) {
            return null;
        }
        $content = $this->zip->getFromName($fullname);
        return $content === false ? null : $content;
    }

    /**
     * SHA-256 e tamanho de uma entrada, por stream.
     *
     * @param string $name
     * @return array|null sha256 e bytes
     */
    public function hash(string $name): ?array {
        $stream = $this->zip->getStream(self::ROOT . $name);
        if ($stream === false) {
            return null;
        }
        $context = hash_init('sha256');
        $bytes = 0;
        while (!feof($stream)) {
            $chunk = fread($stream, 1048576);
            if ($chunk === false) {
                break;
            }
            hash_update($context, $chunk);
            $bytes += strlen($chunk);
        }
        fclose($stream);
        return ['sha256' => hash_final($context), 'bytes' => $bytes];
    }

    /**
     * Percorre as linhas de um ficheiro NDJSON sem o carregar para a memória.
     *
     * @param string $name
     * @param int $maxlinebytes uma linha maior gera false em vez do texto
     * @return \Generator string de cada linha, ou false se exceder o limite
     */
    public function lines(string $name, int $maxlinebytes): \Generator {
        $stream = $this->zip->getStream(self::ROOT . $name);
        if ($stream === false) {
            return;
        }
        try {
            while (($line = fgets($stream, $maxlinebytes + 2)) !== false) {
                if (strlen($line) > $maxlinebytes && substr($line, -1) !== "\n") {
                    yield false;
                    return;
                }
                yield rtrim($line, "\n");
            }
        } finally {
            fclose($stream);
        }
    }
}
