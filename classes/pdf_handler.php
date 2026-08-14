<?php
/**
 * PDF Export Handler using Dompdf
 * Generates RTL Arabic PDF reports with school logo
 */
require_once __DIR__ . '/../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

class PdfHandler {
    
    private $db;
    private $school_name;
    private $logo_path;
    
    public function __construct($db = null) {
        $this->db = $db;
        $this->school_name = 'EduCore';
        $this->logo_path = __DIR__ . '/../assets/img/logo.png';
        
        // Try to get school name and logo from settings
        if ($this->db) {
            try {
                $stmt = $this->db->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('school_name', 'school_logo')");
                if ($stmt) {
                    while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                        if ($row['setting_key'] === 'school_name' && !empty($row['setting_value'])) {
                            $this->school_name = $row['setting_value'];
                        }
                        if ($row['setting_key'] === 'school_logo' && !empty($row['setting_value'])) {
                            $customLogo = __DIR__ . '/../uploads/' . $row['setting_value'];
                            if (file_exists($customLogo)) {
                                $this->logo_path = $customLogo;
                            }
                        }
                    }
                }
            } catch (\Exception $e) {}
        }
    }
    
    /**
     * Generate a PDF from HTML table data
     * @param array $headers - Column headers
     * @param array $rows - Array of row arrays
     * @param string $title - Report title
     * @param string $filename - Download filename (without .pdf)
     * @param array $options - Extra options: subtitle, date_range, orientation
     */
    public function exportTable($headers, $rows, $title, $filename = 'report', $options = []) {
        $orientation = $options['orientation'] ?? 'landscape';
        $subtitle = $options['subtitle'] ?? '';
        $date_range = $options['date_range'] ?? '';
        
        $html = $this->buildTableHtml($headers, $rows, $title, $subtitle, $date_range);
        $this->renderAndDownload($html, $filename, $orientation);
    }
    
    /**
     * Generate PDF from raw HTML content
     */
    public function exportRawHtml($html, $filename = 'report', $orientation = 'portrait') {
        $wrapped = $this->wrapHtml($html, '');
        $this->renderAndDownload($wrapped, $filename, $orientation);
    }
    
    /**
     * Build HTML for a table-based report
     */
    private function buildTableHtml($headers, $rows, $title, $subtitle = '', $date_range = '') {
        $logo_base64 = '';
        if (file_exists($this->logo_path)) {
            $logo_data = file_get_contents($this->logo_path);
            $ext = strtolower(pathinfo($this->logo_path, PATHINFO_EXTENSION));
            $mime = ($ext === 'svg') ? 'image/svg+xml' : (($ext === 'webp') ? 'image/webp' : 'image/' . ($ext === 'jpg' ? 'jpeg' : $ext));
            $logo_base64 = 'data:' . $mime . ';base64,' . base64_encode($logo_data);
        }
        
        $header_html = '';
        if ($logo_base64 || $title) {
            $header_html .= '<div class="report-header">';
            if ($logo_base64) {
                $header_html .= '<img src="' . $logo_base64 . '" class="logo" />';
            }
            $header_html .= '<div class="header-text">';
            $header_html .= '<div class="school-name">' . htmlspecialchars($this->school_name) . '</div>';
            $header_html .= '<div class="report-title">' . htmlspecialchars($title) . '</div>';
            if ($subtitle) {
                $header_html .= '<div class="report-subtitle">' . htmlspecialchars($subtitle) . '</div>';
            }
            if ($date_range) {
                $header_html .= '<div class="report-date">' . htmlspecialchars($date_range) . '</div>';
            }
            $header_html .= '<div class="report-date">تاريخ التصدير: ' . date('Y-m-d H:i') . '</div>';
            $header_html .= '</div></div>';
        }
        
        // Build table
        $table_html = '<table>';
        $table_html .= '<thead><tr>';
        foreach ($headers as $h) {
            $table_html .= '<th>' . htmlspecialchars($h) . '</th>';
        }
        $table_html .= '</tr></thead><tbody>';
        
        if (empty($rows)) {
            $table_html .= '<tr><td colspan="' . count($headers) . '" style="text-align:center;padding:20px;">لا توجد بيانات</td></tr>';
        } else {
            foreach ($rows as $row) {
                $table_html .= '<tr>';
                foreach ($row as $cell) {
                    $table_html .= '<td>' . htmlspecialchars($cell ?? '') . '</td>';
                }
                $table_html .= '</tr>';
            }
        }
        
        $table_html .= '</tbody></table>';
        
        // Footer
        $footer_html = '<div class="report-footer">';
        $footer_html .= htmlspecialchars($this->school_name) . ' - EduCore System';
        $footer_html .= ' | عدد السجلات: ' . count($rows);
        $footer_html .= '</div>';
        
        return $this->wrapHtml($header_html . $table_html . $footer_html, $title);
    }
    
    /**
     * Wrap content in full HTML document with RTL Arabic styles
     */
    private function wrapHtml($body_content, $title) {
        return '<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>' . htmlspecialchars($title) . '</title>
    <style>
        @font-face {
            font-family: "Tajawal";
            src: url("https://fonts.gstatic.com/s/tajawal/v9/Iura6YBj_oCad4k1nzSBC45I.woff2") format("woff2");
        }
        * { box-sizing: border-box; }
        body {
            font-family: "Tajawal", "DejaVu Sans", "Arial", sans-serif;
            font-size: 11px;
            direction: rtl;
            text-align: right;
            color: #333;
            margin: 0;
            padding: 15px;
        }
        .report-header {
            text-align: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #0d6efd;
        }
        .logo {
            height: 60px;
            margin-bottom: 5px;
        }
        .header-text { text-align: center; }
        .school-name {
            font-size: 16px;
            font-weight: bold;
            color: #0d6efd;
            margin-bottom: 3px;
        }
        .report-title {
            font-size: 14px;
            font-weight: bold;
            color: #333;
            margin-bottom: 2px;
        }
        .report-subtitle {
            font-size: 11px;
            color: #666;
        }
        .report-date {
            font-size: 9px;
            color: #999;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
            font-size: 10px;
        }
        th {
            background-color: #0d6efd;
            color: white;
            padding: 6px 4px;
            border: 1px solid #0a58ca;
            font-weight: bold;
            text-align: center;
            white-space: nowrap;
        }
        td {
            padding: 4px;
            border: 1px solid #dee2e6;
            text-align: center;
        }
        tr:nth-child(even) {
            background-color: #f8f9fa;
        }
        tr:hover {
            background-color: #e9ecef;
        }
        .report-footer {
            text-align: center;
            font-size: 9px;
            color: #999;
            border-top: 1px solid #dee2e6;
            padding-top: 8px;
            margin-top: 10px;
        }
        .badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 9px;
            font-weight: bold;
        }
        .badge-success { background: #d4edda; color: #155724; }
        .badge-danger { background: #f8d7da; color: #721c24; }
        .badge-warning { background: #fff3cd; color: #856404; }
        .badge-info { background: #d1ecf1; color: #0c5460; }
        @page {
            margin: 10mm;
        }
    </style>
</head>
<body>' . $body_content . '</body></html>';
    }
    
    /**
     * Render HTML to PDF and trigger download
     */
    private function renderAndDownload($html, $filename, $orientation = 'landscape') {
        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('chroot', realpath(__DIR__ . '/..'));
        
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', $orientation);
        $dompdf->render();
        
        // Stream to browser
        $dompdf->stream($filename . '.pdf', ['Attachment' => true]);
        exit;
    }
    
    /**
     * Export evaluations report to PDF
     */
    public function exportEvaluationsReport($data, $filters = []) {
        $headers = ['#', 'الطالب', 'الفصل', 'نوع التقييم', 'النقاط', 'المعلم', 'الملاحظات', 'التاريخ'];
        $rows = [];
        $i = 1;
        
        foreach ($data as $row) {
            $rows[] = [
                $i++,
                $row['student_name'] ?? '',
                $row['class_name'] ?? '',
                $row['type_name'] ?? '',
                ($row['points'] ?? 0) . ' (' . ($row['reward_type'] ?? '') . ')',
                $row['teacher_name'] ?? '',
                mb_substr($row['notes'] ?? '', 0, 30),
                $row['created_at'] ?? ''
            ];
        }
        
        $subtitle = '';
        if (!empty($filters)) {
            $parts = [];
            if (!empty($filters['class_name'])) $parts[] = 'الفصل: ' . $filters['class_name'];
            if (!empty($filters['date_from'])) $parts[] = 'من: ' . $filters['date_from'];
            if (!empty($filters['date_to'])) $parts[] = 'إلى: ' . $filters['date_to'];
            $subtitle = implode(' | ', $parts);
        }
        
        $this->exportTable($headers, $rows, 'تقرير التقييمات', 'تقرير_التقييمات_' . date('Y-m-d'), [
            'subtitle' => $subtitle,
            'orientation' => 'landscape'
        ]);
    }
    
    /**
     * Export attendance report to PDF
     */
    public function exportAttendanceReport($data, $filters = []) {
        $headers = ['#', 'الطالب', 'الفصل', 'التاريخ', 'الحالة', 'الملاحظات'];
        $status_labels = ['present' => 'حاضر', 'absent' => 'غائب', 'late' => 'متأخر', 'excused' => 'مستأذن'];
        $rows = [];
        $i = 1;
        
        foreach ($data as $row) {
            $rows[] = [
                $i++,
                $row['student_name'] ?? '',
                $row['class_name'] ?? '',
                $row['attendance_date'] ?? '',
                $status_labels[$row['status'] ?? ''] ?? $row['status'] ?? '',
                $row['notes'] ?? ''
            ];
        }
        
        $this->exportTable($headers, $rows, 'تقرير الحضور والغياب', 'تقرير_الحضور_' . date('Y-m-d'), [
            'orientation' => 'landscape'
        ]);
    }
    
    /**
     * Export student list to PDF
     */
    public function exportStudentsList($students, $title = 'قائمة الطلاب') {
        $headers = ['#', 'الاسم', 'الفصل', 'المرحلة', 'الحالة'];
        $rows = [];
        $i = 1;
        
        foreach ($students as $s) {
            $rows[] = [
                $i++,
                $s['name'] ?? '',
                $s['class_name'] ?? '',
                $s['stage_name'] ?? '',
                ($s['status'] ?? '') === 'active' ? 'نشط' : (($s['status'] ?? '') === 'graduated' ? 'خريج' : 'غير نشط')
            ];
        }
        
        $this->exportTable($headers, $rows, $title, 'قائمة_الطلاب_' . date('Y-m-d'), [
            'orientation' => 'portrait'
        ]);
    }
}
