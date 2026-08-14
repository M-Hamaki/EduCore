<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$index = (string) file_get_contents($root . '/index.php');
$loginPartial = (string) file_get_contents($root . '/includes/public_login_portal.php');
$legacyLogin = (string) file_get_contents($root . '/login.php');
$materialsGateway = (string) file_get_contents($root . '/materials.php');
$studentMaterialsIndex = (string) file_get_contents($root . '/student/materials/index.html');
$guestAdapter = (string) file_get_contents($root . '/guest.php');

assert(str_contains($index, 'public_login_portal.php'));
assert(!str_contains($index, 'portalStages'));
assert(!str_contains($index, 'stage-card'));
assert(str_contains($loginPartial, 'auth/microsoft_login.php'));
assert(str_contains($loginPartial, 'name="username"'));
assert(str_contains($loginPartial, 'name="password"'));
assert(!str_contains($loginPartial, 'الدخول كضيف'));
assert(!str_contains($loginPartial, 'portal-guest-link'));
assert(str_contains($loginPartial, 'الذهاب لتحميل الشيتات والملفات مباشرة بدون تسجيل دخول'));
assert(!str_contains($loginPartial, 'name="stage"'));
assert(!str_contains($legacyLogin, 'stage_selected'));
assert(str_contains($materialsGateway, "'student/materials/'"));
assert(str_contains($studentMaterialsIndex, 'مركز التحميلات'));
assert(str_contains($guestAdapter, "'materials.php'"));

echo "UNIFIED_PUBLIC_PORTAL_UI_CONTRACT_TEST_PASSED\n";
