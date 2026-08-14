<?php

require_once __DIR__ . '/includes/import_helpers.php';
require_once __DIR__ . '/../classes/ActivityLog.php';

$activeLevel = $_POST['level'] ?? 'governorates';
$redirectUrl = 'locations.php?level=' . urlencode($activeLevel);
$db = adminImportBootstrap();
validateImportRequest($redirectUrl);

$levelConfig = [
    'governorates' => ['table' => 'governorates', 'fk' => null, 'label' => 'محافظة', 'parent_table' => null],
    'cities' => ['table' => 'cities', 'fk' => 'governorate_id', 'label' => 'مدينة', 'parent_table' => 'governorates'],
    'centers' => ['table' => 'centers', 'fk' => 'city_id', 'label' => 'مركز', 'parent_table' => 'cities'],
    'neighborhoods' => ['table' => 'neighborhoods', 'fk' => 'center_id', 'label' => 'حي', 'parent_table' => 'centers'],
    'streets' => ['table' => 'streets', 'fk' => 'neighborhood_id', 'label' => 'شارع', 'parent_table' => 'neighborhoods'],
];

if (!isset($levelConfig[$activeLevel])) {
    $_SESSION['error_message'] = 'مستوى الاستيراد غير صحيح.';
    header('Location: locations.php');
    exit();
}

$cfg = $levelConfig[$activeLevel];

try {
    $rows = getImportedRows();
    if (empty($rows)) {
        $_SESSION['error_message'] = 'الملف لا يحتوي على بيانات قابلة للاستيراد.';
        header('Location: ' . $redirectUrl);
        exit();
    }

    $existingNames = array_flip(array_map(static function ($value) {
        return normalizeImportHeader((string)$value);
    }, $db->query("SELECT name FROM {$cfg['table']}")->fetchAll(PDO::FETCH_COLUMN)));

    $parentLookup = [];
    if ($cfg['parent_table']) {
        $parents = $db->query("SELECT id, name FROM {$cfg['parent_table']}")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($parents as $parent) {
            $parentLookup[normalizeImportHeader((string)$parent['id'])] = (int)$parent['id'];
            $parentLookup[normalizeImportHeader($parent['name'])] = (int)$parent['id'];
        }
    }

    $sql = $cfg['fk']
        ? "INSERT INTO {$cfg['table']} (name, {$cfg['fk']}, display_order, status) VALUES (?, ?, ?, ?)"
        : "INSERT INTO {$cfg['table']} (name, display_order, status) VALUES (?, ?, ?)";
    $stmt = $db->prepare($sql);

    $imported = 0;
    $errors = [];

    $db->beginTransaction();
    foreach ($rows as $row) {
        $name = importValue($row, ['name', 'الاسم', $cfg['label']]);
        $parentRef = importValue($row, ['parent', 'parent_name', 'الجهةالأعلى', 'المحافظة', 'المدينة', 'المركز', 'الحي']);
        $displayOrder = (int)importValue($row, ['display_order', 'order', 'الترتيب'], '0');
        $status = importValue($row, ['status', 'الحالة'], 'active');

        if ($name === '') {
            $errors[] = 'السطر ' . $row['_row_number'] . ': الاسم مطلوب.';
            continue;
        }

        $normalizedName = normalizeImportHeader($name);
        if (isset($existingNames[$normalizedName])) {
            $errors[] = 'السطر ' . $row['_row_number'] . ': العنصر ' . $name . ' موجود بالفعل.';
            continue;
        }

        $parentId = null;
        if ($cfg['fk']) {
            if ($parentRef === '') {
                $errors[] = 'السطر ' . $row['_row_number'] . ': اسم الجهة الأعلى مطلوب.';
                continue;
            }

            $key = normalizeImportHeader($parentRef);
            if (!isset($parentLookup[$key])) {
                $errors[] = 'السطر ' . $row['_row_number'] . ': الجهة الأعلى ' . $parentRef . ' غير موجودة.';
                continue;
            }
            $parentId = $parentLookup[$key];
        }

        if ($cfg['fk']) {
            $stmt->execute([$name, $parentId, $displayOrder, normalizeImportHeader($status) === 'inactive' ? 'inactive' : 'active']);
        } else {
            $stmt->execute([$name, $displayOrder, normalizeImportHeader($status) === 'inactive' ? 'inactive' : 'active']);
        }
        $existingNames[$normalizedName] = true;
        $imported++;
    }
    $db->commit();

    if ($imported > 0) {
        $_SESSION['success_message'] = "تم استيراد {$imported} عنصر بنجاح.";
        if (!empty($errors)) {
            $_SESSION['success_message'] .= '<br><small>' . htmlspecialchars(implode(' | ', $errors), ENT_QUOTES, 'UTF-8') . '</small>';
        }
        ActivityLog::logImport('location', $imported, ['level' => $activeLevel, 'errors' => count($errors)]);
    } else {
        $_SESSION['error_message'] = implode(' | ', $errors) ?: 'لم يتم استيراد أي بيانات.';
    }
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    $_SESSION['error_message'] = 'فشل استيراد البيانات الجغرافية: ' . $e->getMessage();
}

header('Location: ' . $redirectUrl);
exit();