<?php

namespace App\Exports;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

abstract class ReportSpreadsheet
{
    protected function writeRows(Worksheet $sheet, array $rows, bool $filter = true): void
    {
        foreach ($rows as $index => $row) {
            foreach ($row as $column => $value) {
                if ($value === null) {
                    continue;
                }
                // Keep course and outcome text literal, including text starting with "=".
                $sheet->setCellValueExplicit(Coordinate::stringFromColumnIndex($column + 1).($index + 1), $value,
                    is_int($value) || is_float($value) ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
            }
        }
        $lastColumn = $sheet->getHighestColumn();
        $sheet->getStyle("A1:{$lastColumn}1")->getFont()->setBold(true);
        $sheet->getStyle($sheet->calculateWorksheetDimension())->getAlignment()->setWrapText(true)->setVertical('top');
        for ($column = 1; $column <= Coordinate::columnIndexFromString($lastColumn); $column++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($column))->setWidth(28);
        }
        $sheet->freezePane('A2');
        if ($filter) {
            $sheet->setAutoFilter($sheet->calculateWorksheetDimension());
        }
    }
}
