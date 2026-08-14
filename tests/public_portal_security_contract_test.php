<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$settingsAdapter = (string) file_get_contents($root . '/admin/public_portal_settings.php');
$materialsGateway = (string) file_get_contents($root . '/materials.php');
$studentMaterials = (string) file_get_contents($root . '/student/materials/view.php');
$download = (string) file_get_contents($root . '/material_download.php');
$legacyPortal = (string) file_get_contents($root . '/public_portal.php');
$index = (string) file_get_contents($root . '/index.php');

assert(str_contains($settingsAdapter, "Utilities::validateSession('admin')"));
assert(str_contains($settingsAdapter, 'materials_center.php'));
assert(!str_contains($settingsAdapter, 'REQUEST_METHOD'));
assert(!str_contains($settingsAdapter, 'UpdateGuestServices'));

assert(str_contains($materialsGateway, "'student/materials/'"));
assert(str_contains($materialsGateway, "intro_youtube.php?destination=materials"));
assert(str_contains($studentMaterials, 'new GetPublicMaterials'));
assert(str_contains($studentMaterials, '../../material_download.php?id='));
assert(!str_contains($studentMaterials, '../../uploads/materials/'));

assert(!str_contains($download, "isEnabled('materials')"));
assert(str_contains($download, 'findDownloadableMaterial'));
assert(str_contains($download, 'basename($storedName) !== $storedName'));
assert(str_contains($download, 'realpath($storageRoot . DIRECTORY_SEPARATOR . $storedName)'));
assert(str_contains($download, 'X-Content-Type-Options: nosniff'));

assert(str_contains($index, 'unified_access_portal_enabled'));
assert(str_contains($legacyPortal, 'unified_access_portal_enabled'));
assert(str_contains($legacyPortal, "header('Location: index.php?'"));

echo "PUBLIC_PORTAL_SECURITY_CONTRACT_TEST_PASSED\n";
