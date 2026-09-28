<?php

namespace App\Domain\Operations\Services;

use Dompdf\Dompdf;
use Dompdf\Options;

class ReportFiles
{
    /** @param list<list<string>> $rows */
    public function pdf(array $rows): string
    {
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('isJavascriptEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('chroot', storage_path('app/private'));
        $html = '<html><head><meta charset="utf-8"><style>@page{margin:24pt}body{font-family:DejaVu Sans;font-size:9pt}.row{border-bottom:1px solid #ddd;padding:5pt 0;page-break-inside:avoid}.cell{margin-right:12pt}</style></head><body>';
        foreach ($rows as $row) {
            $html .= '<div class="row">';
            foreach ($row as $cell) {
                if ($cell !== '') {
                    $html .= '<span class="cell">'.htmlspecialchars($cell, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</span> ';
                }
            } $html .= '</div>';
        }
        $pdf = new Dompdf($options);
        $pdf->loadHtml($html.'</body></html>', 'UTF-8');
        $pdf->setPaper('a4', 'landscape');
        $pdf->render();

        return $pdf->output();
    }

    /** Generate an OOXML workbook using stored ZIP entries and literal string cells.
     * @param list<list<string>> $rows */
    public function xlsx(array $rows): string
    {
        $sheet = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach ($rows as $index => $row) {
            $sheet .= '<row r="'.($index + 1).'">';
            foreach ($row as $cell) {
                $safe = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $cell) ?? '';
                $sheet .= '<c t="inlineStr"><is><t xml:space="preserve">'.htmlspecialchars($safe, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</t></is></c>';
            } $sheet .= '</row>';
        }
        $sheet .= '</sheetData></worksheet>';
        $files = [
            '[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>',
            '_rels/.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Operations" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
            'xl/worksheets/sheet1.xml' => $sheet];
        $body = '';
        $central = '';
        foreach ($files as $name => $data) {
            $offset = strlen($body);
            $size = strlen($data);
            $crc = crc32($data);
            $length = strlen($name);
            $body .= pack('VvvvvvVVVvv', 0x04034B50, 20, 0, 0, 0, 33, $crc, $size, $size, $length, 0).$name.$data;
            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014B50, 20, 20, 0, 0, 0, 33, $crc, $size, $size, $length, 0, 0, 0, 0, 0, $offset).$name;
        }

        return $body.$central.pack('VvvvvVVv', 0x06054B50, 0, 0, count($files), count($files), strlen($central), strlen($body), 0);
    }
}
