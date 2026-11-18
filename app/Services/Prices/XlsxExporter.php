<?php

namespace App\Services\Prices;

use RuntimeException;
use ZipArchive;

class XlsxExporter
{
    /**
     * @param  array<int, array<int, mixed>>  $rows
     */
    public function write(array $rows, string $filepath): void
    {
        $sheetFile = tempnam(sys_get_temp_dir(), 'xlsx_sheet_');
        if ($sheetFile === false) {
            throw new RuntimeException('Не удалось создать временный файл');
        }

        $sheet = fopen($sheetFile, 'wb');
        if ($sheet === false) {
            throw new RuntimeException('Не удалось открыть временный файл');
        }

        fwrite($sheet, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>');
        fwrite($sheet, '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>');
        foreach ($rows as $index => $row) {
            $this->writeRow($sheet, $index + 1, $row);
        }
        fwrite($sheet, '</sheetData></worksheet>');
        fclose($sheet);

        $zip = new ZipArchive;
        if ($zip->open($filepath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            unlink($sheetFile);
            throw new RuntimeException('Не удалось создать xlsx');
        }

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="Report" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'</Relationships>');
        $zip->addFile($sheetFile, 'xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($sheetFile);
    }

    /**
     * @param  resource  $handle
     * @param  array<int, mixed>  $values
     */
    private function writeRow($handle, int $rowNum, array $values): void
    {
        fwrite($handle, '<row r="'.$rowNum.'">');
        foreach ($values as $colIndex => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $ref = $this->columnName((int) $colIndex).$rowNum;
            if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value) && ! preg_match('/^0\d+/', $value))) {
                fwrite($handle, '<c r="'.$ref.'"><v>'.$this->escape($value).'</v></c>');

                continue;
            }
            fwrite(
                $handle,
                '<c r="'.$ref.'" t="inlineStr"><is><t>'.$this->escape($value).'</t></is></c>'
            );
        }
        fwrite($handle, '</row>');
    }

    private function columnName(int $index): string
    {
        $name = '';
        $n = $index + 1;
        while ($n > 0) {
            $mod = ($n - 1) % 26;
            $name = chr(65 + $mod).$name;
            $n = intdiv($n - 1, 26);
        }

        return $name;
    }

    private function escape(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
