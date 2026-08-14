<?php

class ExcelHandler {    /**
     * Check if PhpSpreadsheet is available and functional
     * @return bool
     */
    private function isPhpSpreadsheetAvailable() {
        // Check for autoload files
        $autoload_paths = [
            dirname(__FILE__) . '/../vendor/autoload.php',
            'vendor/autoload.php',
            '../vendor/autoload.php',
        ];
        
        foreach ($autoload_paths as $path) {
            if (file_exists($path)) {
                require_once $path;
                
                // Check if classes are available
                if (class_exists('PhpOffice\\PhpSpreadsheet\\Spreadsheet')) {
                    // Test if we can actually create a spreadsheet and writer
                    try {
                        $testSpreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
                        $testWriter = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($testSpreadsheet);
                        
                        // If we got here without exception, it works
                        $testSpreadsheet->disconnectWorksheets();
                        unset($testSpreadsheet, $testWriter);
                        return true;
                        
                    } catch (Exception $e) {
                        // PhpSpreadsheet or its dependencies don't work properly
                        return false;
                    }
                }
            }
        }
        
        return false;
    }
      /**
     * Export data to Excel or CSV file
     * @param array $data
     * @param string $filename
     * @return string Path to the generated file or null for direct download
     */
    public function exportToExcel($data, $filename, array $images = []) {
        // Validate data
        if (!is_array($data) || empty($data)) {
            throw new Exception('بيانات التصدير فارغة أو غير صحيحة');
        }
        
        // Use PhpSpreadsheet if available, otherwise fall back to CSV
        if ($this->isPhpSpreadsheetAvailable()) {
            return $this->exportWithPhpSpreadsheet($data, $filename, $images);
        } else {
            if ($images !== []) {
                throw new RuntimeException('تعذر تضمين الصور: مكتبة إنشاء ملفات Excel غير متاحة.');
            }
            return $this->exportWithAlternativeHandler($data, $filename);
        }
    }
    
    /**
     * Export using PhpSpreadsheet (real Excel file)
     * @param array $data
     * @param string $filename
     * @return string
     */
    private function exportWithPhpSpreadsheet($data, $filename, array $images = []) {
        try {
            // Create spreadsheet
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            
            // Set RTL direction for Arabic
            $sheet->setRightToLeft(true);
            
            // Set properties
            $spreadsheet->getProperties()
                ->setCreator('نظام إدارة النقاط')
                ->setTitle(strip_tags($filename))
                ->setSubject('تقرير النقاط')
                ->setDescription('تقرير تم إنشاؤه من نظام إدارة النقاط');
            
            // Add data
            $row = 1;
            foreach ($data as $rowData) {
                if (is_array($rowData)) {
                    $col = 1;
                    foreach ($rowData as $cellData) {
                        $columnLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
                        $sheet->setCellValue($columnLetter . $row, $cellData);
                        $col++;
                    }
                    $row++;
                }
            }

            // Embed requested images after writing cell values.
            foreach ($images as $image) {
                $imagePath = isset($image['path']) ? (string) $image['path'] : '';
                $imageRow = isset($image['row']) ? (int) $image['row'] : 0;
                $imageColumn = isset($image['column']) ? (int) $image['column'] : 0;
                if ($imagePath === '' || !is_file($imagePath) || $imageRow < 2 || $imageColumn < 1) {
                    continue;
                }

                $columnLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($imageColumn);
                $drawing = new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing();
                $drawing->setName((string) ($image['name'] ?? 'الصورة الشخصية'));
                $drawing->setDescription((string) ($image['description'] ?? 'الصورة الشخصية للطالب'));
                $drawing->setPath($imagePath);
                $drawing->setHeight(60);
                $drawing->setCoordinates($columnLetter . $imageRow);
                $drawing->setOffsetX(6);
                $drawing->setOffsetY(4);
                $drawing->setWorksheet($sheet);
                $sheet->getRowDimension($imageRow)->setRowHeight(52);
                $sheet->getColumnDimension($columnLetter)->setAutoSize(false);
                $sheet->getColumnDimension($columnLetter)->setWidth(14);
            }
            
            // Auto-size columns
            if (!empty($data)) {
                $headers = $data[0];
                foreach (range(1, count($headers)) as $col) {
                    $columnLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
                    $sheet->getColumnDimension($columnLetter)->setAutoSize(true);
                    $sheet->getColumnDimension($columnLetter)->setWidth(15);
                }
            }
            
            // Create filename
            $cleanFilename = preg_replace('/[^a-zA-Z0-9\-_\x{0600}-\x{06FF}]/u', '_', $filename);
            $outputFilename = 'temp_' . $cleanFilename . '_' . date('Y-m-d_H-i-s') . '.xlsx';
            $exportDir = __DIR__ . '/../uploads/exports/';
            if (!is_dir($exportDir)) mkdir($exportDir, 0755, true);
            $filepath = $exportDir . $outputFilename;
            
            // Save file
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $writer->save($filepath);
            
            // Clear memory
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
            
            return $filepath;
            
        } catch (Throwable $e) {
            error_log("PhpSpreadsheet Export Error: " . $e->getMessage());
            if ($images !== []) {
                throw new RuntimeException('تعذر إنشاء ملف Excel متضمناً الصور الشخصية.', 0, $e);
            }
            // Fall back to alternative handler
            return $this->exportWithAlternativeHandler($data, $filename);
        }
    }
      /**
     * Export using alternative CSV handler
     * @param array $data
     * @param string $filename
     * @return string
     */
    private function exportWithAlternativeHandler($data, $filename) {
        require_once __DIR__ . '/alternative_excel_handler.php';
        $alternativeHandler = new AlternativeExcelHandler();
        // For evaluation_reports.php, we need to save to file first then download
        return $alternativeHandler->exportToExcel($data, $filename, true);
    }
    
    /**
     * Export students to Excel
     * @param array $students
     * @return string
     */
    public function exportStudents($students) {
        $data = [];
        
        // Headers
        $headers = ['الرقم', 'الاسم', 'اسم المستخدم', 'الفصل', 'إجمالي النقاط'];
        $data[] = $headers;
          foreach ($students as $student) {
            $class_name = (!empty($student['class_name'])) ? $student['class_name'] : 'غير مُحدد';
            
            // Use the total_points directly from getStudentsWithPoints
            $total_points = isset($student['total_points']) ? $student['total_points'] : 0;
            
            $data[] = [
                $student['id'],
                $student['name'],
                $student['username'],
                $class_name,
                ($total_points >= 0 ? '+' : '') . $total_points
            ];
        }
        
        return $this->exportToExcel($data, 'تقرير_الطلاب');
    }
    
    /**
     * Export evaluations to Excel
     * @param array $evaluations
     * @return string
     */
    /**
     * Export evaluations with optional parameters
     * @param array $evaluations
     * @param string $student_name Optional student name for filename
     * @param string $date_from Optional start date for filename
     * @param string $date_to Optional end date for filename
     * @return array Result with success status and file info
     */
    public function exportEvaluations($evaluations, $student_name = '', $date_from = '', $date_to = '') {
        $data = [];
        
        // Headers
        $headers = ['الرقم', 'الطالب', 'المعلم', 'الفصل', 'نوع التقييم', 'النوع', 'النقاط', 'السبب', 'التاريخ'];
        $data[] = $headers;
        
        foreach ($evaluations as $evaluation) {
            $reason = isset($evaluation['reason']) && $evaluation['reason'] ? $evaluation['reason'] : 'لا يوجد';
            
            // Use display_points and display_type from the query
            $points_display = ($evaluation['display_type'] == 'positive' ? '+' : '-') . $evaluation['display_points'];
            $type_display = $evaluation['display_type'] == 'positive' ? 'إيجابي' : 'سلبي';
            
            $data[] = [
                $evaluation['id'],
                isset($evaluation['student_name']) ? $evaluation['student_name'] : 'غير محدد',
                isset($evaluation['teacher_name']) ? $evaluation['teacher_name'] : 'غير محدد',
                isset($evaluation['class_name']) ? $evaluation['class_name'] : 'غير محدد',
                isset($evaluation['evaluation_name']) ? $evaluation['evaluation_name'] : 'غير محدد',
                $type_display,
                $points_display,
                $reason,
                isset($evaluation['date_created']) ? $evaluation['date_created'] : 'غير محدد'
            ];
        }
        
        // Generate filename
        $filename_parts = ['تقييمات'];
        if (!empty($student_name)) {
            $filename_parts[] = 'الطالب_' . $student_name;
        }
        if (!empty($date_from) && !empty($date_to)) {
            $filename_parts[] = $date_from . '_' . $date_to;
        }
        $filename_parts[] = date('Y-m-d_H-i-s');
        
        $filename = 'temp_' . implode('_', $filename_parts);
        
        try {
            $filepath = $this->exportToExcel($data, $filename);
            
            return [
                'success' => true,
                'filepath' => $filepath,
                'filename' => basename($filepath)
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Export student evaluations to Excel
     * @param array $evaluations
     * @param string $student_name
     * @param string $filename
     * @return string
     */
    public function exportStudentEvaluations($evaluations, $student_name, $filename) {
        $data = [];
        
        // Headers
        $headers = ['الرقم', 'الطالب', 'المعلم', 'الفصل', 'نوع التقييم', 'النوع', 'النقاط', 'السبب', 'التاريخ'];
        $data[] = $headers;
        
        // Calculate totals
        $total_positive = 0;
        $total_negative = 0;
        $total_count = count($evaluations);
        
        foreach ($evaluations as $evaluation) {
            $reason = isset($evaluation['reason']) && $evaluation['reason'] ? $evaluation['reason'] : 'لا يوجد';
            
            // Use the points from display_points which has correct sign
            $points_value = $evaluation['points']; // This is now display_points with correct sign
            $points_display = ($points_value >= 0 ? '+' : '') . $points_value;
            $type_display = $points_value >= 0 ? 'إيجابي' : 'سلبي';
            
            // Add to totals
            if ($points_value >= 0) {
                $total_positive += $points_value;
            } else {
                $total_negative += abs($points_value);
            }
            
            $data[] = [
                $evaluation['id'],
                $student_name,
                isset($evaluation['teacher_name']) ? $evaluation['teacher_name'] : 'غير محدد',
                isset($evaluation['class_name']) ? $evaluation['class_name'] : 'غير محدد',
                isset($evaluation['evaluation_name']) ? $evaluation['evaluation_name'] : 'غير محدد',
                $type_display,
                $points_display,
                $reason,
                isset($evaluation['date_created']) ? $evaluation['date_created'] : 'غير محدد'
            ];
        }
        
        // Add summary rows
        $data[] = []; // Empty row
        $data[] = ['ملخص التقييمات:', '', '', '', '', '', '', '', ''];
        $data[] = ['إجمالي التقييمات:', $total_count, '', '', '', '', '', '', ''];
        $data[] = ['النقاط الإيجابية:', '+' . $total_positive, '', '', '', '', '', '', ''];
        $data[] = ['النقاط السلبية:', '-' . $total_negative, '', '', '', '', '', '', ''];
        $data[] = ['إجمالي النقاط:', '+' . ($total_positive - $total_negative), '', '', '', '', '', '', ''];
        
        // Call exportToExcel and handle file download
        $filepath = $this->exportToExcel($data, $filename);
        
        // If file was created successfully, send it to browser
        if ($filepath && file_exists($filepath)) {
            // Clean the filename for download
            $download_filename = preg_replace('/[^a-zA-Z0-9\-_.\x{0600}-\x{06FF}]/u', '_', $filename) . '.csv';
            
            // Set headers for file download
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $download_filename . '"');
            header('Content-Length: ' . filesize($filepath));
            header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
            header('Expires: 0');
            
            // Output file contents
            readfile($filepath);
            
            // Clean up: delete the temporary file
            unlink($filepath);
            exit;
        } else {
            throw new Exception('فشل في إنشاء ملف التصدير');
        }
    }
    
    /**
     * Import students from Excel file
     * @param string $filepath Path to the Excel file
     * @param array $allowed_class_ids Array of allowed class IDs for the specialist
     * @param int $specialist_id ID of the specialist performing the import
     * @return array Result with success status, imported count, and skipped count
     */
    public function importStudents($filepath, $allowed_class_ids = [], $specialist_id = null) {
        try {
            // Read the file as CSV (since we're using CSV fallback)
            $handle = fopen($filepath, 'r');
            if (!$handle) {
                throw new Exception('فشل في فتح ملف الاستيراد');
            }
            
            // Initialize database connection
            require_once dirname(__FILE__) . '/../config/database.php';
            $database = new Database();
            $db = $database->getConnection();
            
            require_once dirname(__FILE__) . '/user.php';
            $user = new User($db);
            
            $imported = 0;
            $skipped = 0;
            $line_number = 0;
            
            // Skip header row
            fgetcsv($handle);
            $line_number++;
            
            while (($data = fgetcsv($handle)) !== FALSE) {
                $line_number++;
                
                // Skip empty lines
                if (empty(array_filter($data))) {
                    continue;
                }
                
                // Expected columns: name, username, password, class_id
                if (count($data) < 4) {
                    $skipped++;
                    continue;
                }
                
                $name = trim($data[0]);
                $username = trim($data[1]);
                $password = trim($data[2]);
                $class_id = intval($data[3]);
                
                // Validate required fields
                if (empty($name) || empty($username) || empty($password)) {
                    $skipped++;
                    continue;
                }
                
                // Check if class is allowed for specialist
                if (!empty($allowed_class_ids) && !in_array($class_id, $allowed_class_ids)) {
                    $skipped++;
                    continue;
                }
                
                // Check if username already exists
                $check_query = "SELECT id FROM users WHERE username = :username";
                $check_stmt = $db->prepare($check_query);
                $check_stmt->bindParam(':username', $username);
                $check_stmt->execute();
                
                if ($check_stmt->fetch()) {
                    $skipped++;
                    continue;
                }
                
                // Create new student
                $user->name = $name;
                $user->username = $username;
                $user->password = $password;
                $user->role = 'student';
                $user->class_id = $class_id > 0 ? $class_id : null;
                
                if ($user->create()) {
                    $user->ensureStudentProfile((int)$user->id, $name);
                    $imported++;
                } else {
                    $skipped++;
                }
            }
            
            fclose($handle);
            
            return [
                'success' => true,
                'imported' => $imported,
                'skipped' => $skipped,
                'message' => "تم استيراد $imported طالب بنجاح. تم تخطي $skipped سجل."
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'imported' => 0,
                'skipped' => 0,
                'message' => 'خطأ في الاستيراد: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Export teachers to Excel
     * @param array $teachers
     * @param object $db PDO database connection
     * @return string
     */
    public function exportTeachers($teachers, $db = null) {
        $data = [];
        
        // Headers
        $headers = ['الرقم', 'الاسم', 'اسم المستخدم', 'الفصول المسندة', 'المواد', 'الحالة'];
        $data[] = $headers;
        
        // Pre-load class and subject assignments to avoid N+1 queries
        $classMap = [];
        $subjectMap = [];
        if ($db && !empty($teachers)) {
            $teacherIds = array_column($teachers, 'id');
            $placeholders = implode(',', array_fill(0, count($teacherIds), '?'));
            
            $cls_stmt = $db->prepare("SELECT uca.user_id, GROUP_CONCAT(c.name ORDER BY c.name SEPARATOR ', ') AS class_names FROM user_class_access uca JOIN classes c ON uca.class_id = c.id WHERE uca.user_id IN ($placeholders) GROUP BY uca.user_id");
            $cls_stmt->execute($teacherIds);
            foreach ($cls_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $classMap[$row['user_id']] = $row['class_names'];
            }
            
            $subj_stmt = $db->prepare("SELECT ts.teacher_id, GROUP_CONCAT(s.name ORDER BY s.name SEPARATOR ', ') AS subject_names FROM teacher_subjects ts JOIN subjects s ON ts.subject_id = s.id WHERE ts.teacher_id IN ($placeholders) GROUP BY ts.teacher_id");
            $subj_stmt->execute($teacherIds);
            foreach ($subj_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $subjectMap[$row['teacher_id']] = $row['subject_names'];
            }
        }
        
        foreach ($teachers as $teacher) {
            $classNames = $classMap[$teacher['id']] ?? '';
            $subjectNames = $subjectMap[$teacher['id']] ?? '';
            
            $status = (isset($teacher['status']) && $teacher['status'] === 'active') ? 'نشط' : 'معطل';
            
            $data[] = [
                $teacher['id'],
                $teacher['name'],
                $teacher['username'],
                $classNames ?: 'لا توجد',
                $subjectNames ?: 'لا توجد',
                $status
            ];
        }
        
        $filepath = $this->exportToExcel($data, 'تقرير_المعلمين');
        
        if ($filepath && file_exists($filepath)) {
            $download_filename = 'تقرير_المعلمين_' . date('Y-m-d') . '.csv';
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $download_filename . '"');
            header('Content-Length: ' . filesize($filepath));
            header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
            header('Expires: 0');
            readfile($filepath);
            unlink($filepath);
            exit;
        }
    }
    
    /**
     * Export specialists to Excel
     * @param array $specialists
     * @param object $db PDO database connection
     * @return string
     */
    public function exportSpecialists($specialists, $db = null) {
        $data = [];
        
        // Headers
        $headers = ['الرقم', 'الاسم', 'اسم المستخدم', 'الفصول المسندة', 'الحالة'];
        $data[] = $headers;
        
        // Pre-load class assignments to avoid N+1 queries
        $classMap = [];
        if ($db && !empty($specialists)) {
            $specIds = array_column($specialists, 'id');
            $placeholders = implode(',', array_fill(0, count($specIds), '?'));
            
            $cls_stmt = $db->prepare("SELECT sc.specialist_id, GROUP_CONCAT(c.name ORDER BY c.name SEPARATOR ', ') AS class_names FROM specialist_active_classes sc JOIN classes c ON sc.class_id = c.id WHERE sc.specialist_id IN ($placeholders) GROUP BY sc.specialist_id");
            $cls_stmt->execute($specIds);
            foreach ($cls_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $classMap[$row['specialist_id']] = $row['class_names'];
            }
        }
        
        foreach ($specialists as $specialist) {
            $classNames = $classMap[$specialist['id']] ?? '';
            
            $status = (isset($specialist['status']) && $specialist['status'] === 'active') ? 'نشط' : 'معطل';
            
            $data[] = [
                $specialist['id'],
                $specialist['name'],
                $specialist['username'],
                $classNames ?: 'لا توجد',
                $status
            ];
        }
        
        $filepath = $this->exportToExcel($data, 'تقرير_الأخصائيين');
        
        if ($filepath && file_exists($filepath)) {
            $download_filename = 'تقرير_الأخصائيين_' . date('Y-m-d') . '.csv';
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $download_filename . '"');
            header('Content-Length: ' . filesize($filepath));
            header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
            header('Expires: 0');
            readfile($filepath);
            unlink($filepath);
            exit;
        }
    }
    
    /**
     * Export all staff (teachers + specialists + supervisors) to Excel
     * Uses pre-loaded data from readAllStaff() with GROUP_CONCAT
     * @param array $staff Staff array from readAllStaff()
     * @return void
     */
    public function exportStaff($staff) {
        $data = [];
        
        $roleLabels = ['teacher' => 'معلم', 'specialist' => 'أخصائي'];
        // Teacher with is_supervisor flag gets labeled as مشرف
        
        // Headers
        $data[] = ['الرقم', 'الاسم', 'اسم المستخدم', 'الدور', 'الفصول المسندة', 'المواد', 'الحالة'];
        
        foreach ($staff as $s) {
            $roleLabel = $roleLabels[$s['role']] ?? $s['role'];
            if ($s['role'] === 'teacher' && !empty($s['is_supervisor'])) {
                $roleLabel = 'معلم + مشرف';
            }
            $data[] = [
                $s['id'],
                $s['name'],
                $s['username'],
                $roleLabel,
                $s['class_names'] ?: 'لا توجد',
                $s['subject_names'] ?: '-',
                ($s['status'] === 'active') ? 'نشط' : (($s['status'] === 'graduated') ? 'خريج' : 'معطل')
            ];
        }
        
        $filepath = $this->exportToExcel($data, 'تقرير_العاملين');
        
        if ($filepath && file_exists($filepath)) {
            $download_filename = 'تقرير_العاملين_' . date('Y-m-d') . '.csv';
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $download_filename . '"');
            header('Content-Length: ' . filesize($filepath));
            header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
            header('Expires: 0');
            readfile($filepath);
            unlink($filepath);
            exit;
        }
    }

    /**
     * Clean up old export files (older than 1 hour)
     */
    public function cleanupOldExports() {
        $files = glob('temp_*.*');
        $now = time();
        
        foreach ($files as $file) {
            if (is_file($file)) {
                if ($now - filemtime($file) >= 3600) { // 1 hour
                    unlink($file);
                }
            }
        }
    }
}

?>
