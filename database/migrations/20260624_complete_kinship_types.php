<?php

require_once __DIR__ . '/../../config/database.php';
$db = (new Database())->getConnection();

$types = [
    'ابن عم', 'ابنة عم',
    'ابن عمة', 'ابنة عمة',
    'ابن خال', 'ابنة خال',
    'ابن خالة', 'ابنة خالة',
];

$find = $db->prepare('SELECT id FROM kinship_types WHERE name = ? LIMIT 1');
$insert = $db->prepare("INSERT INTO kinship_types (name, status) VALUES (?, 'active')");
foreach ($types as $type) {
    $find->execute([$type]);
    if (!$find->fetchColumn()) {
        $insert->execute([$type]);
    }
}

echo "Kinship types are complete.\n";
