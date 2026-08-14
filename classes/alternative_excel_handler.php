<?php

class AlternativeExcelHandler {
    public function exportToExcel($data, $filename, $saveToFile = false) {
        if (empty($data)) {
            return false;
        }

        $output = fopen('php://temp', 'r+'); // Use php://temp to write to memory first

        // Add UTF-8 BOM for proper Arabic display in Excel
        fwrite($output, "\xEF\xBB\xBF");

        foreach ($data as $row) {
            fputcsv($output, $row);
        }

        rewind($output);
        $csv_content = stream_get_contents($output);
        fclose($output);

        $cleanFilename = preg_replace('/[^a-zA-Z0-9\-_.\x{0600}-\x{06FF}]/u', '_', $filename);
        $outputFilename = 'temp_' . $cleanFilename . '_' . date('Y-m-d_H-i-s') . '.csv';
        $filepath = __DIR__ . '/../uploads/exports/' . $outputFilename; // Save to uploads/exports

        if ($saveToFile) {
            file_put_contents($filepath, $csv_content);
            return $filepath;
        } else {
            // This branch is typically not used when called from ExcelHandler::exportToExcel
            // as it expects a file path. But for completeness, if direct download was intended.
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . basename($outputFilename) . '"');
            echo $csv_content;
            exit;
        }
    }
}
