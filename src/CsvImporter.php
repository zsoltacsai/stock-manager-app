<?php

class CsvImporter
{
    public static function ensureCsv(string $path): string
    {
        $handle = fopen($path, 'rb');
        $head = $handle ? fread($handle, 8) : '';
        if ($handle) {
            fclose($handle);
        }

        $isOleXls = str_starts_with($head, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1");
        $isZipXlsx = str_starts_with($head, "PK\x03\x04");

        if (!$isOleXls && !$isZipXlsx) {
            return $path;
        }

        try {
            if ($isZipXlsx) {
                require_once __DIR__ . '/XlsxReader.php';
                $rows = XlsxReader::readRows($path);
            } else {
                require_once __DIR__ . '/XlsReader.php';
                $rows = XlsReader::readRows($path);
            }
            return self::writeRowsToCsv($rows);
        } catch (Throwable $e) {
            $sofficeResult = self::tryConvertWithSoffice($path);
            if ($sofficeResult !== null) {
                return $sofficeResult;
            }
            throw new RuntimeException(
                'Ez egy Excel fájl (.xls/.xlsx), és a beolvasása nem sikerült (' . $e->getMessage() . '). ' .
                'Nyisd meg Excelben vagy LibreOffice Calc-ban, és mentsd el "CSV UTF-8 (vesszővel ' .
                'elválasztott)" formátumban, majd töltsd fel újra azt a fájlt.'
            );
        }
    }

    private static function writeRowsToCsv(array $rows): string
    {
        if (empty($rows)) {
            throw new RuntimeException('A fájlból nem sikerült egyetlen sort sem beolvasni.');
        }
        $outputDir = sys_get_temp_dir() . '/stockmanager_import_' . bin2hex(random_bytes(6));
        mkdir($outputDir, 0775, true);
        $csvPath = $outputDir . '/import.csv';

        $fh = fopen($csvPath, 'wb');
        fwrite($fh, "\xEF\xBB\xBF");
        foreach ($rows as $row) {
            fputcsv($fh, $row, ',');
        }
        fclose($fh);

        return $csvPath;
    }

    private static function tryConvertWithSoffice(string $path): ?string
    {
        if (!function_exists('exec') || !self::commandExists('soffice')) {
            return null;
        }

        $outputDir = sys_get_temp_dir() . '/stockmanager_import_' . bin2hex(random_bytes(6));
        mkdir($outputDir, 0775, true);

        $cmd = sprintf(
            'soffice --headless --convert-to csv --outdir %s %s 2>&1',
            escapeshellarg($outputDir),
            escapeshellarg($path)
        );
        exec($cmd, $output, $exitCode);

        $csvFiles = glob($outputDir . '/*.csv');
        if ($exitCode !== 0 || empty($csvFiles)) {
            return null;
        }

        return $csvFiles[0];
    }

    private static function commandExists(string $binary): bool
    {
        $which = @shell_exec('command -v ' . escapeshellarg($binary) . ' 2>/dev/null');
        return !empty(trim((string) $which));
    }

    public static function readRows(string $path, array $fieldMap): array
    {
        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException('A fájl nem olvasható.');
        }

        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

        if (!mb_check_encoding($content, 'UTF-8')) {
            $converted = @iconv('Windows-1250', 'UTF-8//IGNORE', $content);
            if ($converted !== false) {
                $content = $converted;
            }
        }

        $firstLineEnd = strpos($content, "\n");
        $firstLine = $firstLineEnd !== false ? substr($content, 0, $firstLineEnd) : $content;
        $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $content);
        rewind($stream);

        $header = fgetcsv($stream, 0, $delimiter);
        if (!$header) {
            fclose($stream);
            return ['header' => [], 'rows' => [], 'matched_fields' => [], 'unmatched_fields' => array_keys($fieldMap)];
        }
        $header = array_map('trim', $header);

        $indexToField = [];
        $matched = [];
        $unmatched = [];
        foreach ($fieldMap as $internal => $sourceHeader) {
            $idx = array_search($sourceHeader, $header, true);
            if ($idx !== false) {
                $indexToField[$idx] = $internal;
                $matched[] = $internal;
            } else {
                $unmatched[] = $internal;
            }
        }

        $rows = [];
        while (($cols = fgetcsv($stream, 0, $delimiter)) !== false) {
            if (count($cols) === 1 && trim((string) $cols[0]) === '') {
                continue;
            }
            $row = [];
            foreach ($indexToField as $idx => $internal) {
                $row[$internal] = isset($cols[$idx]) ? trim((string) $cols[$idx]) : '';
            }
            $rows[] = $row;
        }
        fclose($stream);

        return [
            'header'           => $header,
            'rows'             => $rows,
            'matched_fields'   => $matched,
            'unmatched_fields' => $unmatched,
        ];
    }
}
