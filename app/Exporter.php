<?php
declare(strict_types=1);
namespace Contacts;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Writer\Xls;

final class Exporter
{
    public static function safeCsv(string $value): string
    {
        // A leading apostrophe makes spreadsheet formulas inert, including phones starting with +.
        return preg_match('/^[\s\x{FEFF}]*[=+@\-]/u', $value) || preg_match('/^[\t\r\n]/', $value) ? "'" . $value : $value;
    }

    public static function csv(iterable $rows, $stream, bool $bom = true): void
    {
        if ($bom) { fwrite($stream, "\xef\xbb\xbf"); }
        fputcsv($stream, array_values(Normalizer::FIELDS), ',', '"', '', "\r\n");
        foreach ($rows as $row) {
            fputcsv($stream, array_map(fn($key) => self::safeCsv((string)$row[$key]), array_keys(Normalizer::FIELDS)), ',', '"', '', "\r\n");
        }
    }

    public static function xls(iterable $rows, string $path): void
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet()->setTitle('Контакты');
        $all = (function () use ($rows) { yield array_values(Normalizer::FIELDS); foreach ($rows as $row) { yield array_map(fn($k) => (string)$row[$k], array_keys(Normalizer::FIELDS)); } })();
        $r = 1;
        foreach ($all as $row) {
            if ($r > 65536) { throw new \RuntimeException('XLS поддерживает максимум 65535 контактов. Используйте CSV.'); }
            foreach ($row as $i => $value) { $sheet->setCellValueExplicit([$i + 1, $r], $value, DataType::TYPE_STRING); }
            $r++;
        }
        $sheet->getStyle('A1:E1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle('A1:E1')->getFill()->setFillType('solid')->getStartColor()->setRGB('2454D6');
        foreach (['A' => 38, 'B' => 55, 'C' => 35, 'D' => 35, 'E' => 45] as $col => $width) { $sheet->getColumnDimension($col)->setWidth($width); }
        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:E' . ($r - 1));
        $writer = new Xls($book);
        $writer->setPreCalculateFormulas(false);
        $writer->save($path);
        $book->disconnectWorksheets();
    }
}
