<?php

namespace App\Helpers;

use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use RuntimeException;

class BloomSpreadsheetReader
{
    /**
     * Read the supplied workbook format without evaluating formulas or writing data.
     *
     * @return array{entries: array<int, array{domain: string, position: int, level: string, term: string}>, source_rows: int, duplicate_rows: int}
     */
    public static function read(string $path): array
    {
        if (! is_file($path) || ! is_readable($path) || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'xlsx') {
            throw new RuntimeException('Provide a readable .xlsx file.');
        }

        $reader = new Xlsx();
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly(['VERBS']);
        $workbook = $reader->load($path);

        try {
            $sheet = $workbook->getSheetByName('VERBS');
            if ($sheet === null) {
                throw new RuntimeException('The workbook must contain a VERBS sheet.');
            }
            foreach (['B' => 'Domain', 'C' => 'N', 'D' => 'Proto-verb', 'E' => 'Verb'] as $column => $header) {
                if (trim((string) $sheet->getCell($column.'4')->getValue()) !== $header) {
                    throw new RuntimeException("Cell {$column}4 must have the header '{$header}'.");
                }
            }

            $entries = [];
            $levelNames = [];
            $sourceRows = 0;
            $duplicates = 0;
            for ($row = 5; $row <= $sheet->getHighestDataRow(); $row++) {
                $values = [];
                foreach (['B', 'C', 'D', 'E'] as $column) {
                    $cell = $sheet->getCell($column.$row);
                    if ($cell->getDataType() === 'f') {
                        throw new RuntimeException("Row {$row}: columns B–E must contain values, not formulas.");
                    }
                    $values[] = trim((string) $cell->getValue());
                }
                if ($values === ['', '', '', '']) {
                    continue;
                }
                if (in_array('', $values, true)) {
                    throw new RuntimeException("Row {$row}: Domain, N, Proto-verb and Verb are all required.");
                }
                [$domain, $number, $level, $term] = $values;
                $position = filter_var($number, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
                if ($position === false) {
                    throw new RuntimeException("Row {$row}: N must be a positive integer within the database range.");
                }
                foreach ([$domain, $level, $term] as $text) {
                    if (mb_strlen($text, 'UTF-8') > 255) {
                        throw new RuntimeException("Row {$row}: reference text must not exceed 255 characters.");
                    }
                }

                $levelKey = json_encode([mb_strtolower($domain, 'UTF-8'), $position]);
                $normalizedLevel = mb_strtolower($level, 'UTF-8');
                if (isset($levelNames[$levelKey]) && $levelNames[$levelKey] !== $normalizedLevel) {
                    throw new RuntimeException("Row {$row}: conflicting Proto-verb for the same domain and level number.");
                }
                $levelNames[$levelKey] = $normalizedLevel;
                $key = json_encode([$levelKey, mb_strtolower($term, 'UTF-8')]);
                $sourceRows++;
                if (isset($entries[$key])) {
                    $duplicates++;
                    continue;
                }
                $entries[$key] = ['domain' => $domain, 'position' => $position, 'level' => $level, 'term' => $term];
            }

            if ($entries === []) {
                throw new RuntimeException('The VERBS sheet contains no reference entries.');
            }

            return ['entries' => array_values($entries), 'source_rows' => $sourceRows, 'duplicate_rows' => $duplicates];
        } finally {
            $workbook->disconnectWorksheets();
        }
    }
}
