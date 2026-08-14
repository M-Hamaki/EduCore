<?php
/**
 * Excel Export Configuration and Enhancements
 * Additional configuration for better Arabic Excel support
 */

// Define Excel export constants
define('EXCEL_RTL_SUPPORT', true);
define('EXCEL_ARABIC_FONT', 'Arial');
define('EXCEL_DEFAULT_FONT_SIZE', 11);
define('EXCEL_HEADER_FONT_SIZE', 12);

/**
 * Enhanced Excel Handler Helper Functions
 */
class ExcelHelper {
    
    /**
     * Convert Arabic numbers to English numbers for Excel compatibility
     * @param string $text
     * @return string
     */
    public static function convertArabicNumbers($text) {
        $arabic = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
        $english = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
        return str_replace($arabic, $english, $text);
    }
    
    /**
     * Format date for Excel with Arabic support
     * @param string $date
     * @return string
     */
    public static function formatDateForExcel($date) {
        if (empty($date)) return '';
        
        try {
            $dateTime = new DateTime($date);
            return $dateTime->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            return $date;
        }
    }
    
    /**
     * Sanitize text for Excel export
     * @param string $text
     * @return string
     */
    public static function sanitizeForExcel($text) {
        if (is_null($text)) return '';
        
        // Convert to string if not already
        $text = (string) $text;
        
        // Remove any Excel formula characters that might cause issues
        $text = str_replace(['=', '+', '-', '@'], ['', '', '', ''], $text);
        
        // Convert Arabic numbers
        $text = self::convertArabicNumbers($text);
        
        // Trim whitespace
        $text = trim($text);
        
        return $text;
    }
    
    /**
     * Get proper Arabic filename for download
     * @param string $basename
     * @return string
     */
    public static function getArabicFilename($basename) {
        $timestamp = date('Y-m-d_H-i-s');
        return $basename . '_' . $timestamp . '.xlsx';
    }
    
    /**
     * Set download headers for Arabic Excel files
     * @param string $filename
     */
    public static function setDownloadHeaders($filename) {
        // Clear any previous output
        if (ob_get_level()) {
            ob_end_clean();
        }
        
        header('Content-Description: File Transfer');
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename*=UTF-8\'\'' . rawurlencode($filename));
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Pragma: public');
    }
    
    /**
     * Create a properly formatted Excel sheet with Arabic support
     * @param \PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet
     * @param array $data
     * @param string $sheetTitle
     */
    public static function formatArabicSheet($spreadsheet, $data, $sheetTitle = null) {
        $sheet = $spreadsheet->getActiveSheet();
        
        // Set sheet title if provided
        if ($sheetTitle) {
            $sheet->setTitle($sheetTitle);
        }
        
        // Set RTL direction
        $sheet->setRightToLeft(true);
        
        // Set default font
        $spreadsheet->getDefaultStyle()->getFont()
            ->setName(EXCEL_ARABIC_FONT)
            ->setSize(EXCEL_DEFAULT_FONT_SIZE);
        
        return $sheet;
    }
    
    /**
     * Apply Arabic-friendly cell formatting
     * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet
     * @param int $row
     * @param int $col
     * @param mixed $value
     * @param bool $isHeader
     */
    public static function setCellWithArabicFormatting($sheet, $row, $col, $value, $isHeader = false) {
        $cell = $sheet->getCellByColumnAndRow($col, $row);
        
        // Sanitize value
        $value = self::sanitizeForExcel($value);
        $cell->setValue($value);
        
        // Apply formatting
        if ($isHeader) {
            $cell->getStyle()->getFont()
                ->setBold(true)
                ->setSize(EXCEL_HEADER_FONT_SIZE);
            $cell->getStyle()->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()->setARGB('FFE0E0E0');
        }
        
        $cell->getStyle()->getAlignment()
            ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)
            ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER)
            ->setWrapText(true);
            
        $cell->getStyle()->getBorders()->getAllBorders()
            ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
    }
}

/**
 * Excel export validation functions
 */
function validateExcelExportData($data) {
    if (!is_array($data) || empty($data)) {
        throw new Exception('بيانات التصدير فارغة أو غير صحيحة');
    }
    
    return true;
}

function validatePhpSpreadsheetLibrary() {
    if (!file_exists('vendor/autoload.php')) {
        throw new Exception('مكتبة PhpSpreadsheet غير مثبتة. يرجى تشغيل composer install');
    }
    
    if (!class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet')) {
        require_once 'vendor/autoload.php';
    }
    
    return true;
}

/**
 * Test Excel export functionality
 */
function testExcelExport() {
    try {
        validatePhpSpreadsheetLibrary();
        
        $testData = [
            ['الرقم', 'الاسم', 'النقاط'],
            [1, 'أحمد محمد', '+85'],
            [2, 'فاطمة علي', '+92'],
            [3, 'محمد حسن', '+70']
        ];
        
        validateExcelExportData($testData);
        
        return [
            'success' => true,
            'message' => 'اختبار تصدير Excel نجح بنجاح'
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'خطأ في اختبار تصدير Excel: ' . $e->getMessage()
        ];
    }
}
?>
