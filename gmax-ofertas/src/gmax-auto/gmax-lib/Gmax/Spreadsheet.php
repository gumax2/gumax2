<?php
declare(strict_types=1);

namespace Gmax;

use RuntimeException;
use ZipArchive;

/** Leitura de CSV (vírgula ou ponto e vírgula) e XLSX simples, sem bibliotecas externas. */
final class Spreadsheet
{
    public const MAX_BYTES = 5 * 1024 * 1024;
    public const MAX_ROWS = 2000;
    private const PREFERRED_SHEETS = ['Ofertas ML', 'Ofertas', 'Links para converter', 'Candidatos GMAX'];

    /** @return list<list<string>> linhas (a primeira é o cabeçalho) */
    public static function read(string $path, string $originalName): array
    {
        clearstatcache(true, $path);
        $size = filesize($path);
        if ($size === false || $size === 0) {
            throw new RuntimeException('Arquivo vazio');
        }
        if ($size > self::MAX_BYTES) {
            throw new RuntimeException('Arquivo acima de 5 MB');
        }
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $head = (string)file_get_contents($path, false, null, 0, 4);
        if ($ext === 'xlsx' || $head === "PK\x03\x04") {
            return self::readXlsx($path);
        }
        if (!in_array($ext, ['csv', 'txt', ''], true)) {
            throw new RuntimeException('Formato não suportado: envie .csv ou .xlsx');
        }
        return self::readCsv($path);
    }

    /** @return list<list<string>> */
    public static function readCsv(string $path): array
    {
        $raw = (string)file_get_contents($path);
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        }
        $raw = (string)preg_replace('/^\xEF\xBB\xBF/', '', $raw);
        $firstLine = strtok($raw, "\n") ?: '';
        $delim = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
        $f = fopen('php://temp', 'w+b');
        fwrite($f, $raw);
        rewind($f);
        $rows = [];
        while (($cells = fgetcsv($f, 0, $delim, '"', '')) !== false) {
            if ($cells === [null]) {
                continue;
            }
            $rows[] = array_map(static fn($c) => trim((string)$c), $cells);
            if (count($rows) > self::MAX_ROWS + 1) {
                throw new RuntimeException('Limite de ' . self::MAX_ROWS . ' linhas por arquivo');
            }
        }
        fclose($f);
        return $rows;
    }

    /** @return list<list<string>> */
    public static function readXlsx(string $path): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('Servidor sem extensão zip: exporte a planilha como CSV');
        }
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('XLSX inválido ou corrompido');
        }
        try {
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $total += (int)($zip->statIndex($i)['size'] ?? 0);
            }
            if ($total > 15 * 1024 * 1024) {
                throw new RuntimeException('XLSX descompactado excede o limite de segurança');
            }
            $main = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
            $relNs = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
            $load = static function (string $name) use ($zip, $main, $relNs): ?\DOMXPath {
                $data = $zip->getFromName($name);
                if ($data === false) {
                    return null;
                }
                if (stripos($data, '<!DOCTYPE') !== false) {
                    throw new RuntimeException('XLSX com DOCTYPE não é aceito');
                }
                $doc = new \DOMDocument();
                if (!$doc->loadXML((string)preg_replace('/^\xEF\xBB\xBF/', '', $data), LIBXML_NONET | LIBXML_COMPACT)) {
                    throw new RuntimeException('XLSX com XML inválido');
                }
                $xp = new \DOMXPath($doc);
                $xp->registerNamespace('m', $main);
                $xp->registerNamespace('r', $relNs);
                $xp->registerNamespace('pr', 'http://schemas.openxmlformats.org/package/2006/relationships');
                return $xp;
            };
            $shared = [];
            if ($ss = $load('xl/sharedStrings.xml')) {
                foreach ($ss->query('/m:sst/m:si') as $si) {
                    $text = '';
                    foreach ($ss->query('.//m:t', $si) as $t) {
                        $text .= $t->textContent;
                    }
                    $shared[] = $text;
                }
            }
            $wb = $load('xl/workbook.xml') ?? throw new RuntimeException('XLSX sem workbook');
            $targets = [];
            if ($rels = $load('xl/_rels/workbook.xml.rels')) {
                foreach ($rels->query('/pr:Relationships/pr:Relationship') as $rel) {
                    $targets[$rel->getAttribute('Id')] = $rel->getAttribute('Target');
                }
            }
            $sheets = [];
            foreach ($wb->query('/m:workbook/m:sheets/m:sheet') as $s) {
                $target = $targets[$s->getAttributeNS($relNs, 'id')] ?? '';
                $target = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . $target;
                $sheets[$s->getAttribute('name')] = $target;
            }
            if (!$sheets) {
                throw new RuntimeException('XLSX sem planilhas');
            }
            $chosen = reset($sheets);
            foreach (self::PREFERRED_SHEETS as $name) {
                if (isset($sheets[$name])) {
                    $chosen = $sheets[$name];
                    break;
                }
            }
            $sheet = $load($chosen) ?? throw new RuntimeException('Aba não encontrada no XLSX');
            $rows = [];
            foreach ($sheet->query('/m:worksheet/m:sheetData/m:row') as $row) {
                $cells = [];
                $next = 0;
                foreach ($sheet->query('m:c', $row) as $c) {
                    $ref = $c->getAttribute('r');
                    $col = $ref !== '' ? self::colIndex((string)preg_replace('/\d+/', '', $ref)) : $next;
                    if ($col > 200) {
                        throw new RuntimeException('Planilha com colunas demais (máximo 200)');
                    }
                    $next = $col + 1;
                    $type = $c->getAttribute('t');
                    $v = $sheet->query('m:v', $c)->item(0)?->textContent ?? '';
                    $value = match ($type) {
                        's' => $shared[(int)$v] ?? '',
                        'inlineStr' => implode('', array_map(static fn($t) => $t->textContent, iterator_to_array($sheet->query('.//m:t', $c)))),
                        'b' => $v === '1' ? 'VERDADEIRO' : 'FALSO',
                        default => $v,
                    };
                    $cells[$col] = trim($value);
                }
                if (!$cells || implode('', $cells) === '') {
                    continue;
                }
                $line = array_fill(0, max(array_keys($cells)) + 1, '');
                foreach ($cells as $i => $v) {
                    $line[$i] = $v;
                }
                $rows[] = $line;
                if (count($rows) > self::MAX_ROWS + 1) {
                    throw new RuntimeException('Limite de ' . self::MAX_ROWS . ' linhas por arquivo');
                }
            }
            return $rows;
        } finally {
            $zip->close();
        }
    }

    private static function colIndex(string $letters): int
    {
        $n = 0;
        foreach (str_split(strtoupper($letters)) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }
        return $n - 1;
    }

    public static function normalizeHeader(string $h): string
    {
        $h = mb_strtolower(trim((string)preg_replace('/^\xEF\xBB\xBF/', '', $h)), 'UTF-8');
        $h = strtr($h, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c']);
        return (string)preg_replace('/\s+/', ' ', $h);
    }
}
