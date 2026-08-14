<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Infrastructure;

use EduCore\Modules\Finance\Contracts\FinanceExportRenderer as FinanceExportRendererContract;
use RuntimeException;

final class FinanceExportRenderer implements FinanceExportRendererContract
{
    public function render(string $format, array $columns, array $rows): string
    {
        return match ($format) {
            'csv' => $this->csv($columns, $rows),
            'xlsx' => $this->xlsx($columns, $rows),
            'pdf' => $this->pdf($columns, $rows),
            default => throw new RuntimeException('Unsupported finance export format.'),
        };
    }

    private function csv(array $columns, array $rows): string
    {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) { throw new RuntimeException('CSV export stream could not be opened.'); }
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, $columns);
        foreach ($rows as $row) {
            fputcsv($stream, array_map(fn (string $column): string => $this->safeCell($row[$column] ?? ''), $columns));
        }
        rewind($stream);
        $contents = stream_get_contents($stream);
        fclose($stream);
        if ($contents === false) { throw new RuntimeException('CSV export could not be rendered.'); }
        return $contents;
    }

    private function xlsx(array $columns, array $rows): string
    {
        if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
            throw new RuntimeException('PhpSpreadsheet is required for XLSX exports.');
        }
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setRightToLeft(true);
        $line = 1;
        foreach ($columns as $index => $column) {
            $sheet->setCellValueExplicit([$index + 1, $line], $column, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        }
        foreach ($rows as $row) {
            ++$line;
            foreach ($columns as $index => $column) {
                $sheet->setCellValueExplicit([$index + 1, $line], $this->safeCell($row[$column] ?? ''), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            }
        }
        ob_start();
        try {
            (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save('php://output');
            $contents = ob_get_contents();
        } finally {
            ob_end_clean();
            $spreadsheet->disconnectWorksheets();
        }
        if ($contents === false) { throw new RuntimeException('XLSX export could not be rendered.'); }
        return $contents;
    }

    private function pdf(array $columns, array $rows): string
    {
        if (!class_exists(\Dompdf\Dompdf::class)) {
            throw new RuntimeException('Dompdf is required for PDF exports.');
        }
        $html = '<html lang="ar" dir="rtl"><meta charset="UTF-8"><style>body{font-family:DejaVu Sans,sans-serif}table{width:100%;border-collapse:collapse}th,td{border:1px solid #999;padding:5px;text-align:right}</style><table><thead><tr>';
        foreach ($columns as $column) { $html .= '<th>' . htmlspecialchars($column, ENT_QUOTES, 'UTF-8') . '</th>'; }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($columns as $column) { $html .= '<td>' . htmlspecialchars($this->safeCell($row[$column] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>'; }
            $html .= '</tr>';
        }
        $html .= '</tbody></table></html>';
        $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => false]);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();
        return $dompdf->output();
    }

    private function safeCell(mixed $value): string
    {
        $text = is_scalar($value) || $value === null ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return preg_match('/^[=+\-@]/', $text) === 1 ? "'" . $text : $text;
    }
}
