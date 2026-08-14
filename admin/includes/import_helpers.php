<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

function normalizeImportHeader(string $value): string
{
    $value = trim(mb_strtolower($value, 'UTF-8'));
    return preg_replace('/[\s_\-\(\)\[\]\/\\]+/u', '', $value) ?? '';
}

function adminImportBootstrap(): PDO
{
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../classes/utilities.php';
    require_once __DIR__ . '/../../includes/session_config.php';
    require_once __DIR__ . '/../../includes/csrf.php';

    Utilities::validateSession('admin');
    requireCsrfPost();

    $database = new Database();
    return $database->getConnection();
}

function validateImportRequest(string $redirectUrl): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location: ' . $redirectUrl);
        exit();
    }

    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        $_SESSION['error_message'] = 'خطأ في التحقق من الأمان';
        header('Location: ' . $redirectUrl);
        exit();
    }
}

function getImportedRows(string $fileField = 'file'): array
{
    if (!isset($_FILES[$fileField]) || !is_array($_FILES[$fileField])) {
        throw new RuntimeException('لم يتم اختيار ملف للاستيراد.');
    }

    $file = $_FILES[$fileField];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('حدث خطأ أثناء رفع الملف.');
    }

    $extension = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    if (!in_array($extension, ['xlsx', 'xls', 'csv'], true)) {
        throw new RuntimeException('يجب أن يكون الملف بصيغة Excel أو CSV.');
    }

    $spreadsheet = IOFactory::load($file['tmp_name']);
    $rows = $spreadsheet->getActiveSheet()->toArray('', true, true, false);

    if (count($rows) < 2) {
        return [];
    }

    $headers = array_map(static function ($header) {
        return normalizeImportHeader((string)$header);
    }, $rows[0]);

    $result = [];
    for ($i = 1; $i < count($rows); $i++) {
        $rawRow = $rows[$i];
        $assoc = ['_row_number' => $i + 1];
        $hasValue = false;

        foreach ($headers as $index => $header) {
            if ($header === '') {
                continue;
            }

            $value = trim((string)($rawRow[$index] ?? ''));
            if ($value !== '') {
                $hasValue = true;
            }
            $assoc[$header] = $value;
        }

        if ($hasValue) {
            $result[] = $assoc;
        }
    }

    return $result;
}

function importValue(array $row, array $aliases, string $default = ''): string
{
    foreach ($aliases as $alias) {
        $key = normalizeImportHeader($alias);
        if (isset($row[$key]) && trim((string)$row[$key]) !== '') {
            return trim((string)$row[$key]);
        }
    }

    return $default;
}

function importBool(string $value, bool $default = false): int
{
    $normalized = normalizeImportHeader($value);
    if ($normalized === '') {
        return $default ? 1 : 0;
    }

    $truthy = ['1', 'true', 'yes', 'active', 'core', 'اساسية', 'أساسية', 'نشط', 'مفعل', 'مفعّل'];
    return in_array($normalized, array_map('normalizeImportHeader', $truthy), true) ? 1 : 0;
}

function importList(string $value): array
{
    if (trim($value) === '') {
        return [];
    }

    $parts = preg_split('/[,،;|]+/u', $value) ?: [];
    $parts = array_map('trim', $parts);
    return array_values(array_filter($parts, static function ($item) {
        return $item !== '';
    }));
}
