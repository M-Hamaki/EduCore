<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$materials = (string) file_get_contents($root . '/src/Modules/PublicPortal/Infrastructure/LegacyMaterialCatalogAdapter.php');
$query = (string) file_get_contents($root . '/src/Modules/PublicPortal/Application/GetPublicMaterials.php');

assert(str_contains($materials, 'm.enabled = 1'));
assert(str_contains($materials, 'm.downloadable = 1'));
assert(str_contains($materials, "s.status = \'active\'"));
assert(str_contains($materials, "g.status = \'active\'"));
assert(!str_contains($materials, "'../../uploads/materials/'"));
assert(!str_contains($query, 'PublicPortalRepository'));
assert(!file_exists($root . '/src/Modules/PublicPortal/Domain/GuestAccessPolicy.php'));
assert(!file_exists($root . '/src/Modules/PublicPortal/Domain/PublicServiceCatalog.php'));
assert(!file_exists($root . '/src/Modules/PublicPortal/Infrastructure/PdoPublicPortalRepository.php'));

echo "PUBLIC_PORTAL_FOUNDATION_CONTRACT_TEST_PASSED\n";
