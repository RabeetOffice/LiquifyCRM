<?php

declare(strict_types=1);

namespace App\Modules\Core\Support;

use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Reader\Xls;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;

/**
 * A CSV or Excel file read for an import: the tabs of the workbook (a CSV
 * is one tab) and the rows of one tab at a time, with empty rows left out.
 * Only the tab asked for is loaded, so a big workbook stays quick.
 */
final class Spreadsheet
{
    /** @return list<array{name: string, rows: int}> */
    public static function tabs(string $path, string $extension): array
    {
        $tabs = [];

        foreach (self::reader($extension)->listWorksheetInfo($path) as $info) {
            $tabs[] = ['name' => (string) $info['worksheetName'], 'rows' => (int) $info['totalRows']];
        }

        return $tabs;
    }

    /**
     * The rows of one tab that hold something, cells as the file has them.
     *
     * @return list<list<mixed>>
     */
    public static function rows(string $path, string $extension, string $tab): array
    {
        $reader = self::reader($extension);

        if (! $reader instanceof Csv) {
            $reader->setLoadSheetsOnly([$tab]);
        }

        $book = $reader->load($path);
        $sheet = $book->getSheetByName($tab) ?? $book->getActiveSheet();
        $rows = [];

        foreach ($sheet->toArray(null, true, false, false) as $row) {
            $row = array_values($row);

            if (array_filter($row, fn (mixed $cell): bool => $cell !== null && $cell !== '') !== []) {
                $rows[] = $row;
            }
        }

        $book->disconnectWorksheets();

        return $rows;
    }

    private static function reader(string $extension): Xlsx|Xls|Csv
    {
        $reader = match (mb_strtolower($extension)) {
            'xlsx' => new Xlsx,
            'xls' => new Xls,
            default => new Csv,
        };

        $reader->setReadDataOnly(true);
        $reader->setReadEmptyCells(false);

        return $reader;
    }
}
