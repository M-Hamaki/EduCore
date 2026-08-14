<?php

declare(strict_types=1);

// Compatibility only: guest mode was removed. Old links now use the
// anonymous materials route without creating a guest account or role.
$target = 'materials.php';
if ((string) ($_GET['from_teams'] ?? '') === '1') {
    $target .= '?from_teams=1';
}

header('Location: ' . $target, true, 302);
exit;
