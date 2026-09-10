<?php
declare(strict_types=1);
namespace Contacts;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

final class TableReader
{
    public function __construct(private array $config) {}

    public function read(string $path, string $extension, int $sheetIndex = 0, string $encoding = 'auto', string $delimiter = 'auto'): array
    {
        if (!is_file($path) || filesize($path) > $this->config['max_upload_bytes']) {
            throw new \InvalidArgumentException('Файл отсутствует или превышает допустимый размер.');
        }
        if ($extension === 'csv') { return $this->csv($path, $encoding, $delimiter); }
        if (!in_array($extension, ['xls', 'xlsx'], true)) { throw new \InvalidArgumentException('Поддерживаются XLS, XLSX и CSV.'); }
        $zip = new \ZipArchive();
        if ($zip->open($path) === true) {
            $size = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) { $size += $zip->statIndex($i)['size']; }
            $zip->close();
            if ($size > 80 * 1024 * 1024) { throw new \InvalidArgumentException('Распакованный XLSX превышает 80 МБ. Разделите файл.'); }
        }
        $type = IOFactory::identify($path, ['Xls', 'Xlsx']);
        $reader = IOFactory::createReader($type);
        $info = $reader->listWorksheetInfo($path);
        if (!isset($info[$sheetIndex])) { throw new \InvalidArgumentException('Лист не найден.'); }
        $sheetInfo = $info[$sheetIndex];
        $this->limits((int)$sheetInfo['totalRows'], (int)$sheetInfo['totalColumns']);
        $reader->setLoadSheetsOnly($sheetInfo['worksheetName']);
        $reader->setReadEmptyCells(false);
        $book = $reader->load($path);
        $sheet = $book->getSheet(0);
        $height = $sheet->getHighestDataRow();
        $width = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
        $this->limits($height, $width);
        $rows = [];
        for ($r = 1; $r <= $height; $r++) {
            $row = [];
            for ($c = 1; $c <= $width; $c++) {
                $cell = $sheet->getCell([$c, $r]);
                // Never execute formulas from an uploaded workbook.
                $value = $cell->getDataType() === 'f' ? ($cell->getOldCalculatedValue() ?? $cell->getValue()) : $cell->getValue();
                if ($value instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) { $value = $value->getPlainText(); }
                if (is_numeric($value) && $cell->getDataType() !== 's') {
                    $value = NumberFormat::toFormattedString($value, $cell->getStyle()->getNumberFormat()->getFormatCode());
                }
                $row[] = $this->cell((string)($value ?? ''));
            }
            $rows[] = $row;
        }
        $book->disconnectWorksheets();
        return ['rows' => $rows, 'sheets' => array_column($info, 'worksheetName'), 'sheet' => $sheetIndex];
    }

    private function limits(int $rows, int $columns): void
    {
        if ($rows > $this->config['max_rows'] + 100 || $columns > $this->config['max_columns'] || $rows * $columns > $this->config['max_cells']) {
            throw new \InvalidArgumentException('Слишком большая таблица: максимум ' . $this->config['max_rows'] . ' строк, ' . $this->config['max_columns'] . ' колонок и ' . $this->config['max_cells'] . ' ячеек. Разделите файл.');
        }
    }

    private function cell(string $value): string
    {
        if (strlen($value) > 32767) { throw new \InvalidArgumentException('В таблице есть ячейка длиннее 32767 байт.'); }
        return str_replace("\0", '', $value);
    }

    private function csv(string $path, string $encoding, string $delimiter): array
    {
        $data = file_get_contents($path);
        if ($encoding === 'auto') {
            $encoding = str_starts_with($data, "\xff\xfe") ? 'UTF-16LE' : (str_starts_with($data, "\xfe\xff") ? 'UTF-16BE' : (mb_check_encoding($data, 'UTF-8') ? 'UTF-8' : 'Windows-1251'));
        }
        if (!in_array($encoding, ['UTF-8', 'Windows-1251', 'UTF-16LE', 'UTF-16BE'], true)) { throw new \InvalidArgumentException('Неизвестная кодировка.'); }
        $data = mb_convert_encoding($data, 'UTF-8', $encoding);
        $data = preg_replace('/^\x{FEFF}/u', '', $data);
        if (str_contains($data, "\0")) { throw new \InvalidArgumentException('Файл не похож на текстовый CSV.'); }
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, $data);
        if ($delimiter === 'auto') {
            $best = -1;
            foreach ([',', ';', "\t"] as $candidate) {
                rewind($stream);
                $widths = [];
                for ($i = 0; $i < 15 && ($r = fgetcsv($stream, 0, $candidate, '"', '')) !== false; $i++) {
                    if ($r !== [null]) { $widths[] = count($r); }
                }
                $counts = array_count_values($widths);
                arsort($counts);
                $width = (int)array_key_first($counts);
                $score = $width > 1 ? (reset($counts) / max(1, count($widths))) * 100 + min(50, $width) : 0;
                if ($score > $best) { $best = $score; $delimiter = $candidate; }
            }
        }
        if ($delimiter === 'tab') { $delimiter = "\t"; }
        if (!in_array($delimiter, [',', ';', "\t"], true)) { throw new \InvalidArgumentException('Неизвестный разделитель CSV.'); }
        rewind($stream);
        $rows = [];
        $width = 0;
        while (($row = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
            $width = max($width, count($row));
            $this->limits(count($rows) + 1, $width);
            $rows[] = array_map(fn($v) => $this->cell((string)($v ?? '')), $row);
        }
        fclose($stream);
        foreach ($rows as &$row) { $row = array_pad($row, $width, ''); }
        unset($row);
        return ['rows' => $rows, 'sheets' => ['CSV'], 'sheet' => 0, 'encoding' => $encoding, 'delimiter' => $delimiter];
    }

    public static function table(array $rows, int $headerRow, bool $hasHeader): array
    {
        if ($headerRow < 1 || $headerRow > count($rows)) { throw new \InvalidArgumentException('Неверный номер строки заголовков.'); }
        $width = count($rows[$headerRow - 1]);
        $headers = $hasHeader ? $rows[$headerRow - 1] : array_fill(0, $width, '');
        foreach ($headers as $i => &$header) { $header = trim((string)$header) ?: 'Колонка ' . ($i + 1); }
        unset($header);
        $data = [];
        for ($i = $headerRow - ($hasHeader ? 0 : 1); $i < count($rows); $i++) {
            if (count(array_filter($rows[$i], fn($v) => trim((string)$v) !== '')) === 0) { continue; }
            $data[] = ['number' => $i + 1, 'values' => $rows[$i]];
        }
        if (!$data) { throw new \InvalidArgumentException('После заголовков нет строк с данными.'); }
        return ['headers' => $headers, 'data' => $data];
    }
}
