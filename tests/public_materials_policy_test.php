<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Modules/PublicPortal/Contracts/MaterialCatalogQuery.php';
require_once __DIR__ . '/../src/Modules/PublicPortal/Infrastructure/LegacyMaterialCatalogAdapter.php';
require_once __DIR__ . '/../src/Modules/PublicPortal/Application/GetPublicMaterials.php';

use EduCore\Modules\PublicPortal\Application\GetPublicMaterials;
use EduCore\Modules\PublicPortal\Infrastructure\LegacyMaterialCatalogAdapter;

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("CREATE TABLE stages (id INTEGER PRIMARY KEY, stage_name TEXT, stage_order INTEGER, status TEXT)");
$db->exec("CREATE TABLE grades (id INTEGER PRIMARY KEY, grade_name TEXT, grade_order INTEGER, stage_id INTEGER, status TEXT)");
$db->exec("CREATE TABLE materials (
    id INTEGER PRIMARY KEY,
    subject_name TEXT,
    original_file_name TEXT,
    file_name TEXT,
    file_size INTEGER,
    downloadable INTEGER,
    term TEXT,
    stage_id INTEGER,
    grade_id INTEGER,
    enabled INTEGER,
    sort_order INTEGER
)");
$db->exec("INSERT INTO stages VALUES (1, 'نشطة', 1, 'active'), (2, 'مغلقة', 2, 'inactive')");
$db->exec("INSERT INTO grades VALUES (1, 'الأول', 1, 1, 'active'), (2, 'الثاني', 2, 2, 'active')");
$db->exec("INSERT INTO materials VALUES
    (1, 'منشورة', 'one.pdf', 'one-safe.pdf', 100, 1, 'term1', 1, 1, 1, 1),
    (2, 'عرض فقط', 'two.pdf', 'two-safe.pdf', 200, 0, 'term1', 1, 1, 1, 2),
    (3, 'غير منشورة', 'three.pdf', 'three-safe.pdf', 300, 1, 'term1', 1, 1, 0, 3),
    (4, 'مرحلة مغلقة', 'four.pdf', 'four-safe.pdf', 400, 1, 'term1', 2, 2, 1, 4)");

$adapter = new LegacyMaterialCatalogAdapter($db);
$query = new GetPublicMaterials($adapter);
$result = $query->execute(['page' => 1, 'per_page' => 24]);

assert($result['enabled'] === true);
assert($result['pagination']['total'] === 2);
assert(count($result['materials']) === 2);
assert($adapter->findDownloadableMaterial(1)['file_name'] === 'one-safe.pdf');
assert($adapter->findDownloadableMaterial(2) === null);
assert($adapter->findDownloadableMaterial(3) === null);
assert($adapter->findDownloadableMaterial(4) === null);

echo "PUBLIC_MATERIALS_POLICY_TEST_PASSED\n";
